<?php

namespace LaraGrep\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaraGrep\Metadata\SchemaMetadataLoader;
use RuntimeException;
use function collect;

class LaraGrepQueryService
{
    protected array $baseConfig = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $cachedMetadata = [];

    protected ?string $currentContext = null;

    public function __construct(
        protected SchemaMetadataLoader $metadataLoader,
        protected array $config = [],
        protected ?ConversationStore $conversationStore = null
    ) {
        $this->baseConfig = $this->normalizeConfig($this->config);
        $this->config = $this->baseConfig;
    }

    public function buildPrompt(string $question): string
    {
        $metadata = $this->getMetadata();
        $metadataSummary = collect($metadata)
            ->map(function (array $table) {
                $columnSummary = collect($table['columns'] ?? [])
                    ->map(function (array $column) {
                        $description = $column['description'] ?? '';
                        $type = $column['type'] ?? '';

                        return sprintf(
                            '- %s (%s)%s',
                            $column['name'],
                            $type ?: 'unknown',
                            $description ? ': ' . $description : ''
                        );
                    })
                    ->implode(PHP_EOL);

                $relationshipSummary = collect($table['relationships'] ?? [])
                    ->map(function (array $relationship) {
                        $type = $relationship['type'] ?? 'unknown';
                        $relatedTable = $relationship['table'] ?? 'unknown';
                        $foreignKey = $relationship['foreign_key'] ?? null;

                        return sprintf(
                            '- %s %s%s',
                            $type,
                            $relatedTable,
                            $foreignKey ? sprintf(' (foreign key: %s)', $foreignKey) : ''
                        );
                    })
                    ->implode(PHP_EOL);

                $tableDescription = trim(($table['description'] ?? '') ?: '');

                $sections = array_filter([
                    $columnSummary !== '' ? "Columns:\n" . $columnSummary : null,
                    $relationshipSummary !== '' ? "Relationships:\n" . $relationshipSummary : null,
                ]);

                return sprintf(
                    "Table %s%s\n%s",
                    $table['name'],
                    $tableDescription ? ' — ' . $tableDescription : '',
                    implode(PHP_EOL . PHP_EOL, $sections)
                );
            })
            ->implode(PHP_EOL . PHP_EOL);

        return implode(PHP_EOL . PHP_EOL, array_filter([
            $this->buildDatabaseContextLine(),
            'Use the available schema to produce one or more safe SQL SELECT queries that answer the user\'s question. If multiple steps are required, describe them in the order they should be executed.',
            'Respond strictly in JSON with the format {"steps": [{"query": "...", "bindings": []}, ...]}. Only generate parameterized SELECT statements and never produce CREATE, INSERT, UPDATE, DELETE, DROP, ALTER, or any other mutating commands. If the user requests any write operation or an unsafe action, respond instead with {"steps": [], "summary": "<polite refusal in the user language>"}.',
            'If the question can be answered without running a database query (for example, it only references prior conversation, is outside the scope of the schema, or requests unsupported data), respond with {"steps": [], "summary": "<clear explanation in the user language>"}.',
            'Only reference tables that are explicitly listed in the schema summary. If the necessary table is missing, do not guess—return {"steps": [], "summary": "<explain the limitation in the user language>"}.',
            'User language: ' . $this->getUserLanguage(),
            'Available schema:',
            $metadataSummary,
            'Question: ' . $question,
        ]));
    }

    public function answerQuestion(string $question, bool $debug = false, ?string $context = null, ?string $conversationId = null): array
    {
        return $this->withContext($context, function () use ($question, $debug, $conversationId) {
            $conversationId = $this->normalizeConversationId($conversationId);
            $history = $conversationId !== null
                ? $this->getConversationMessages($conversationId)
                : [];

            $queryMessages = $this->buildQueryMessages($question, $history);
            $queryResponse = $this->callModel($queryMessages);
            $plan = $this->interpretQueryPlanResponse($queryResponse);

            if ($plan['steps'] === []) {
                $summary = $plan['summary'] ?? 'Sorry, I can only help with read-only queries.';

                if ($conversationId !== null) {
                    $this->storeConversationExchange($conversationId, $question, $summary);
                }

                $answer = ['summary' => $summary];

                if ($debug) {
                    $answer['steps'] = [];
                    $answer['results'] = [];
                }

                return $answer;
            }

            $planSteps = $plan['steps'];

            $executedSteps = [];
            $debugQueries = [];

            foreach ($planSteps as $step) {
                $execution = $this->runSelectQuery($step['query'], $step['bindings'], $debug);

                $executedSteps[] = [
                    'query' => $step['query'],
                    'bindings' => $step['bindings'],
                    'results' => $execution['results'],
                ];

                if ($debug) {
                    $debugQueries = array_merge($debugQueries, $execution['queries']);
                }
            }

            $interpretationMessages = $this->buildInterpretationMessages(
                $question,
                $executedSteps
            );

            $finalResponse = $this->callModel($interpretationMessages);
            $summary = $this->interpretFinalResponse($finalResponse);

            if ($conversationId !== null) {
                $this->storeConversationExchange($conversationId, $question, $summary);
            }

            $answer = [
                'summary' => $summary,
            ];

            if ($debug) {
                $answer['steps'] = $executedSteps;

                if ($executedSteps !== []) {
                    $firstStep = $executedSteps[0];

                    $answer['bindings'] = $firstStep['bindings'];
                    $answer['results'] = $firstStep['results'];
                } else {
                    $answer['results'] = [];
                }

                $answer['debug'] = [
                    'queries' => $debugQueries,
                ];
            }

            return $answer;
        });
    }

    protected function buildQueryMessages(string $question, array $history = []): array
    {
        $messages = [];
        $systemPrompt = trim((string) ($this->config['system_prompt'] ?? ''));

        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;

            if (!is_string($role) || !is_string($content)) {
                continue;
            }

            $role = trim(strtolower($role));
            $content = trim($content);

            if ($content === '') {
                continue;
            }

            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        $messages[] = ['role' => 'user', 'content' => $this->buildPrompt($question)];

        return $messages;
    }

    /**
     * @param array<int, array{query: string, bindings: array<int, mixed>, results: array<int, array<string, mixed>>}> $executedSteps
     */
    protected function buildInterpretationMessages(
        string $question,
        array $executedSteps
    ): array {
        $messages = [];
        $systemPrompt = trim((string) ($this->config['interpretation_prompt'] ?? ''));

        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $stepsForModel = array_map(
            function (array $step) {
                return [
                    'query' => $step['query'],
                    'bindings' => array_values($step['bindings']),
                    'results' => $step['results'],
                ];
            },
            $executedSteps
        );

        $messages[] = [
            'role' => 'user',
            'content' => implode(PHP_EOL . PHP_EOL, [
                'Original question: ' . $question,
                'Executed queries (JSON): ' . json_encode($stepsForModel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                sprintf(
                    'Provide a concise, business-oriented summary in %s that only reports the requested result. Format the entire response as valid HTML, using semantic elements such as <p>, <ul>, <ol>, or <table> when appropriate. Do not mention SQL, queries, bindings, code, or technical terms. Explain what the numbers mean only if the user explicitly asked for that. If the list is empty, politely state that no records were found using HTML as well. Never return plain text or Markdown.',
                    $this->getUserLanguage()
                ),
            ]),
        ];

        return $messages;
    }

    protected function normalizeConversationId(?string $conversationId): ?string
    {
        if ($conversationId === null) {
            return null;
        }

        $conversationId = trim((string) $conversationId);

        return $conversationId === '' ? null : $conversationId;
    }

    protected function getConversationMessages(string $conversationId): array
    {
        if ($this->conversationStore === null) {
            return [];
        }

        return $this->conversationStore->getMessages($conversationId);
    }

    protected function storeConversationExchange(string $conversationId, string $question, string $summary): void
    {
        if ($this->conversationStore === null) {
            return;
        }

        $this->conversationStore->appendExchange($conversationId, $question, $summary);
    }

    protected function getMetadata(): array
    {
        $cacheKey = $this->currentContext ?? '__default__';

        if (array_key_exists($cacheKey, $this->cachedMetadata)) {
            return $this->cachedMetadata[$cacheKey];
        }

        $configured = $this->config['metadata'] ?? [];

        if (!is_array($configured)) {
            $configured = [];
        }

        $configured = array_values(array_filter(
            $configured,
            fn ($table) => is_array($table)
        ));

        $excludeTables = $this->config['exclude_tables'] ?? [];

        if (!is_array($excludeTables)) {
            $excludeTables = [];
        }

        $loaded = $this->metadataLoader->load(
            $this->config['connection'] ?? null,
            $excludeTables
        );

        $metadata = array_values(array_merge($loaded, $configured));

        return $this->cachedMetadata[$cacheKey] = $metadata;
    }

    protected function callModel(array $messages): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (!$apiKey) {
            throw new RuntimeException('Missing OpenAI API key.');
        }

        if ($messages === []) {
            throw new RuntimeException('No messages provided to the language model.');
        }

        $response = Http::withToken($apiKey)
            ->post($this->config['base_url'] ?? 'https://api.openai.com/v1/chat/completions', [
                'model' => $this->config['model'] ?? 'gpt-3.5-turbo',
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to call language model: ' . $response->body());
        }

        return $response->json();
    }

    protected function interpretQueryPlanResponse(array $response): array
    {
        $content = Arr::get($response, 'choices.0.message.content');

        if (!$content) {
            throw new RuntimeException('Language model did not return a message.');
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Language model response was not valid JSON.');
        }

        $steps = $decoded['steps'] ?? null;

        if (!is_array($steps)) {
            throw new RuntimeException('Language model response did not include query steps.');
        }

        $normalized = [];

        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw new RuntimeException('Language model response returned an invalid step.');
            }

            $query = isset($step['query']) ? trim((string) $step['query']) : '';

            if ($query === '') {
                throw new RuntimeException(sprintf('Language model response did not include a SQL query for step %d.', $index + 1));
            }

            if (!Str::startsWith(strtolower($query), 'select')) {
                throw new RuntimeException('Only SELECT queries are allowed.');
            }

            $this->assertTablesExistInMetadata($query);

            $bindings = $step['bindings'] ?? [];

            if (!is_array($bindings)) {
                throw new RuntimeException('Language model response provided invalid bindings.');
            }

            $normalized[] = [
                'query' => $query,
                'bindings' => array_values($bindings),
            ];
        }

        $summary = isset($decoded['summary']) && is_string($decoded['summary'])
            ? trim($decoded['summary'])
            : null;

        if ($normalized === [] && $summary === null) {
            throw new RuntimeException('Language model response did not include query steps.');
        }

        return [
            'steps' => $normalized,
            'summary' => $summary,
        ];
    }

    protected function interpretFinalResponse(array $response): string
    {
        $content = Arr::get($response, 'choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Language model did not return a final answer.');
        }

        return trim($content);
    }

    /**
     * @param array<int, mixed> $bindings
     * @param array<int, array<string, mixed>> $results
     * @return array{results: array<int, array<string, mixed>>, queries: array<int, array<string, mixed>>}
     */
    protected function runSelectQuery(string $query, array $bindings, bool $debug = false): array
    {
        return $this->usingConfiguredConnection(function (ConnectionInterface $connection) use ($query, $bindings, $debug) {
            $queries = [];

            if ($debug) {
                $connection->flushQueryLog();
                $connection->enableQueryLog();
            }

            try {
                $results = collect($connection->select($query, $bindings))
                    ->map(fn ($row) => (array) $row)
                    ->all();
            } finally {
                if ($debug) {
                    $queries = collect($connection->getQueryLog())
                        ->map(function (array $entry) {
                            return [
                                'query' => $entry['query'] ?? '',
                                'bindings' => $entry['bindings'] ?? [],
                                'time' => $entry['time'] ?? null,
                            ];
                        })
                        ->all();

                    $connection->disableQueryLog();
                    $connection->flushQueryLog();
                }
            }

            return [
                'results' => $results,
                'queries' => $queries,
            ];
        });
    }

    protected function assertTablesExistInMetadata(string $query): void
    {
        $knownTables = $this->getKnownTableNames();

        if ($knownTables === []) {
            return;
        }

        $tablesInQuery = $this->extractTableNamesFromQuery($query);

        foreach ($tablesInQuery as $table) {
            if (!in_array($table, $knownTables, true)) {
                throw new RuntimeException(sprintf('Query references unknown table "%s".', $table));
            }
        }
    }

    protected function getKnownTableNames(): array
    {
        return collect($this->getMetadata())
            ->map(fn (array $table) => strtolower((string) ($table['name'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function extractTableNamesFromQuery(string $query): array
    {
        $pattern = '/\b(?:from|join)\s+([`"\[]?[\w.]+[`"\]]?(?:\s+as)?(?:\s+[\w`"\[]+)*)/i';

        if (!preg_match_all($pattern, $query, $matches)) {
            return [];
        }

        $tables = collect($matches[1] ?? [])
            ->map(function ($match) {
                $table = trim((string) $match);
                $table = preg_replace('/\s+as\s+.*/i', '', $table) ?? $table;
                $table = preg_split('/\s+/', $table)[0] ?? $table;
                $table = trim($table, "`\"[]");

                if (str_contains($table, '.')) {
                    $parts = explode('.', $table);
                    $table = end($parts) ?: $table;
                }

                return strtolower($table);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $tables;
    }

    /**
     * @template TReturn
     * @param callable(ConnectionInterface):TReturn $callback
     * @return TReturn
     */
    protected function usingConfiguredConnection(callable $callback)
    {
        $connectionName = $this->config['connection'] ?? null;
        $previous = null;
        $shouldRestore = false;

        if (is_string($connectionName) && $connectionName !== '') {
            $previous = DB::getDefaultConnection();
            $shouldRestore = $previous !== $connectionName;

            if ($shouldRestore) {
                DB::setDefaultConnection($connectionName);
            }

            $connection = DB::connection($connectionName);
        } else {
            $connection = DB::connection();
        }

        try {
            return $callback($connection);
        } finally {
            if ($shouldRestore && $previous !== null) {
                DB::setDefaultConnection($previous);
            }
        }
    }

    protected function buildDatabaseContextLine(): ?string
    {
        $database = $this->config['database'] ?? null;

        if (!is_array($database)) {
            return null;
        }

        $type = isset($database['type']) ? trim((string) $database['type']) : '';
        $name = isset($database['name']) ? trim((string) $database['name']) : '';

        if ($type === '' && $name === '') {
            return null;
        }

        if ($type !== '' && $name !== '') {
            return sprintf('Database: %s — %s', $type, $name);
        }

        $value = $type !== '' ? $type : $name;

        return sprintf('Database: %s', $value);
    }

    protected function getUserLanguage(): string
    {
        $language = $this->config['user_language'] ?? null;

        if (!is_string($language)) {
            return 'en';
        }

        $language = trim($language);

        return $language !== '' ? $language : 'en';
    }

    /**
     * @template TReturn
     * @param callable():TReturn $callback
     * @return TReturn
     */
    protected function withContext(?string $context, callable $callback)
    {
        $previousConfig = $this->config;
        $previousContext = $this->currentContext;

        if ($context === null) {
            $this->currentContext = null;
            $this->config = $this->baseConfig;
        } else {
            $this->currentContext = $context;
            $overrides = [];

            $contexts = $this->baseConfig['contexts'] ?? [];

            if (is_array($contexts)) {
                $contextOverrides = $contexts[$context] ?? [];

                if (is_array($contextOverrides)) {
                    $overrides = $contextOverrides;
                }
            }

            $this->config = array_replace_recursive($this->baseConfig, $overrides);
        }

        try {
            return $callback();
        } finally {
            $this->config = $previousConfig;
            $this->currentContext = $previousContext;
        }
    }

    protected function normalizeConfig(array $config): array
    {
        $normalized = $config;

        $normalized['metadata'] = $this->normalizeTables($normalized['metadata'] ?? []);
        $normalized['exclude_tables'] = $this->normalizeExcludeTables($normalized['exclude_tables'] ?? []);
        $normalized['database'] = $this->normalizeDatabase($normalized['database'] ?? []);

        $legacyContext = $this->normalizeContextArray($normalized['context'] ?? []);
        unset($normalized['context']);

        $normalized['contexts'] = $this->normalizeContextsArray($normalized['contexts'] ?? []);

        if ($legacyContext !== []) {
            $normalized['contexts']['default'] = array_replace_recursive(
                $normalized['contexts']['default'] ?? [],
                $legacyContext
            );
        }

        $defaultContext = $normalized['contexts']['default'] ?? [];

        if ($defaultContext === [] && (
            ($normalized['metadata'] ?? []) !== [] ||
            ($normalized['exclude_tables'] ?? []) !== [] ||
            ($normalized['database'] ?? []) !== [] ||
            ($normalized['connection'] ?? null) !== null
        )) {
            $defaultContext = $this->normalizeContextArray([
                'metadata' => $normalized['metadata'],
                'exclude_tables' => $normalized['exclude_tables'],
                'database' => $normalized['database'],
                'connection' => $normalized['connection'] ?? null,
            ]);

            if ($defaultContext !== []) {
                $normalized['contexts']['default'] = $defaultContext;
            }
        }

        if ($defaultContext !== []) {
            foreach (['connection', 'exclude_tables', 'database', 'metadata'] as $key) {
                if (array_key_exists($key, $defaultContext)) {
                    $normalized[$key] = $defaultContext[$key];
                }
            }
        }

        return $normalized;
    }

    protected function normalizeContextArray($context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $normalized = $context;

        if (array_key_exists('tables', $normalized)) {
            $tables = $this->normalizeTables($normalized['tables']);
            $normalized['tables'] = $tables;
            $normalized['metadata'] = $tables;
        }

        if (array_key_exists('metadata', $normalized)) {
            $normalized['metadata'] = $this->normalizeTables($normalized['metadata']);
        }

        if (array_key_exists('exclude_tables', $normalized)) {
            $normalized['exclude_tables'] = $this->normalizeExcludeTables($normalized['exclude_tables']);
        }

        if (array_key_exists('database', $normalized)) {
            $normalized['database'] = $this->normalizeDatabase($normalized['database']);
        }

        return $normalized;
    }

    protected function normalizeContextsArray($contexts): array
    {
        if (!is_array($contexts)) {
            return [];
        }

        $normalized = [];

        foreach ($contexts as $name => $context) {
            if (!is_array($context)) {
                continue;
            }

            $normalized[$name] = $this->normalizeContextArray($context);
        }

        return $normalized;
    }

    protected function normalizeTables($tables): array
    {
        if (!is_array($tables)) {
            return [];
        }

        return array_values(array_filter(
            $tables,
            fn ($table) => is_array($table)
        ));
    }

    protected function normalizeExcludeTables($excludeTables): array
    {
        if (is_string($excludeTables)) {
            $excludeTables = array_map('trim', explode(',', $excludeTables));
        }

        if (!is_array($excludeTables)) {
            return [];
        }

        $values = array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $excludeTables
        );

        return array_values(array_filter(
            $values,
            fn ($value) => $value !== null && $value !== ''
        ));
    }

    protected function normalizeDatabase($database): array
    {
        if (!is_array($database)) {
            return [];
        }

        $normalized = [];

        if (array_key_exists('type', $database)) {
            $normalized['type'] = (string) $database['type'];
        }

        if (array_key_exists('name', $database)) {
            $normalized['name'] = (string) $database['name'];
        }

        return $normalized;
    }
}
