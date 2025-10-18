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
    protected ?array $cachedMetadata = null;

    public function __construct(
        protected SchemaMetadataLoader $metadataLoader,
        protected array $config = []
    ) {
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
            'Utilize o esquema disponível para produzir uma ou mais consultas SQL SELECT seguras que respondam à pergunta do usuário. Caso precise de múltiplos passos, descreva-os na ordem em que devem ser executados.',
            'Responda estritamente em JSON com o formato {"steps": [{"query": "...", "bindings": []}, ...]}. Utilize apenas consultas SELECT parametrizadas e jamais execute comandos CREATE, INSERT, UPDATE, DELETE, DROP ou ALTER.',
            'Esquema disponível:',
            $metadataSummary,
            'Pergunta: ' . $question,
        ]));
    }

    public function answerQuestion(string $question, bool $debug = false): array
    {
        $queryMessages = $this->buildQueryMessages($question);
        $queryResponse = $this->callModel($queryMessages);
        $planSteps = $this->interpretQueryPlanResponse($queryResponse);

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

        $answer = [
            'summary' => $summary,
            'steps' => $executedSteps,
        ];

        if ($executedSteps !== []) {
            $firstStep = $executedSteps[0];

            $answer['query'] = $firstStep['query'];
            $answer['bindings'] = $firstStep['bindings'];
            $answer['results'] = $firstStep['results'];
        }

        if ($debug) {
            $answer['debug'] = [
                'queries' => $debugQueries,
            ];
        }

        return $answer;
    }

    protected function buildQueryMessages(string $question): array
    {
        $messages = [];
        $systemPrompt = trim((string) ($this->config['system_prompt'] ?? ''));

        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
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
                'Pergunta original: ' . $question,
                'Consultas executadas (JSON): ' . json_encode($stepsForModel, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'Produza uma resposta em português, direta e voltada para o negócio, apenas informando o resultado solicitado, sem explicar o que ele significa a menos que o usuário peça isso explicitamente. Não mencione SQL, consultas, queries, bindings, código ou termos técnicos. Caso a lista esteja vazia, informe que nenhum registro foi encontrado.',
            ]),
        ];

        return $messages;
    }

    protected function getMetadata(): array
    {
        if ($this->cachedMetadata !== null) {
            return $this->cachedMetadata;
        }

        $configured = $this->config['metadata'] ?? [];
        $loaded = $this->metadataLoader->load();

        $metadata = array_values(array_merge($loaded, $configured));

        return $this->cachedMetadata = $metadata;
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

    /**
     * @return array<int, array{query: string, bindings: array<int, mixed>}>
     */
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

        if (!is_array($steps) || $steps === []) {
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

            $bindings = $step['bindings'] ?? [];

            if (!is_array($bindings)) {
                throw new RuntimeException('Language model response provided invalid bindings.');
            }

            $normalized[] = [
                'query' => $query,
                'bindings' => array_values($bindings),
            ];
        }

        return $normalized;
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
        $connection = $this->getConnection();
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
    }

    protected function getConnection(): ConnectionInterface
    {
        $connection = $this->config['connection'] ?? null;

        return $connection ? DB::connection($connection) : DB::connection();
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
            return sprintf('Banco de dados: %s — %s', $type, $name);
        }

        $value = $type !== '' ? $type : $name;

        return sprintf('Banco de dados: %s', $value);
    }
}
