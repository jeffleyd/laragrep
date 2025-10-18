<?php

namespace LaraGrep\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaraGrep\Metadata\SchemaMetadataLoader;
use RuntimeException;
use function collect;

class LaraGrepQueryService
{
    protected ?array $cachedMetadata = null;

    /**
     * @var array<string, class-string<Model>>
     */
    protected array $metadataModelMap = [];

    /**
     * @var array<string, string>
     */
    protected array $metadataTableMap = [];

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

                $model = is_string($table['model'] ?? null) ? trim($table['model']) : '';
                $modelNote = $model !== '' ? ' (Model: ' . $model . ')' : '';
                $tableDescription = trim(($table['description'] ?? '') ?: '');

                return sprintf(
                    "Table %s%s%s\n%s",
                    $table['name'],
                    $modelNote,
                    $tableDescription ? ' — ' . $tableDescription : '',
                    $columnSummary
                );
            })
            ->implode(PHP_EOL . PHP_EOL);

        $instructions = $this->config['system_prompt'] ?? 'You are a helpful assistant that translates questions into database queries.';

        return implode(PHP_EOL . PHP_EOL, array_filter([
            $instructions,
            'Respond strictly in JSON with the format: {"steps": [{"type": "eloquent"|"raw", ...}], "summary": "..."}.',
            'Prefer Eloquent builder operations. Only fallback to raw queries when necessary. For raw queries, only SELECT statements with parameter bindings are allowed.',
            'Available schema information:',
            $metadataSummary,
            'Question: ' . $question,
        ]));
    }

    public function answerQuestion(string $question, bool $debug = false): array
    {
        $prompt = $this->buildPrompt($question);
        $response = $this->callModel($prompt);
        $parsed = $this->interpretResponse($response);
        $steps = $parsed['steps'];

        $execution = $this->executeSteps($steps, $debug);

        $answer = [
            'summary' => $parsed['summary'],
            'steps' => $steps,
            'results' => $execution['results'],
        ];

        if ($debug) {
            $answer['debug'] = [
                'queries' => $execution['queries'],
            ];
        }

        return $answer;
    }

    protected function getMetadata(): array
    {
        if ($this->cachedMetadata !== null) {
            return $this->cachedMetadata;
        }

        $configured = $this->config['metadata'] ?? [];
        $loaded = $this->metadataLoader->load();

        $metadata = array_values(array_merge($loaded, $configured));
        $this->metadataModelMap = $this->buildModelMap($metadata);
        $this->metadataTableMap = $this->buildTableMap($metadata);

        return $this->cachedMetadata = $metadata;
    }

    protected function callModel(string $prompt): array
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (!$apiKey) {
            throw new RuntimeException('Missing OpenAI API key.');
        }

        $response = Http::withToken($apiKey)
            ->post($this->config['base_url'] ?? 'https://api.openai.com/v1/chat/completions', [
                'model' => $this->config['model'] ?? 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to call language model: ' . $response->body());
        }

        return $response->json();
    }

    protected function interpretResponse(array $response): array
    {
        $content = Arr::get($response, 'choices.0.message.content');

        if (!$content) {
            throw new RuntimeException('Language model did not return a message.');
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Language model response was not valid JSON.');
        }

        $steps = $decoded['steps'] ?? [];

        if (!is_array($steps)) {
            throw new RuntimeException('Language model response is missing steps.');
        }

        $summary = $decoded['summary'] ?? null;

        return [
            'steps' => $steps,
            'summary' => is_string($summary) ? $summary : null,
        ];
    }

    protected function executeSteps(array $steps, bool $debug = false): array
    {
        $connection = $this->getConnection();
        $queries = [];
        $results = [];

        if ($debug) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
        }

        try {
            $results = collect($steps)
                ->map(function (array $step) use ($connection) {
                    return match ($step['type'] ?? null) {
                        'eloquent' => $this->runEloquentStep($step),
                        'raw' => $this->runRawStep($step, $connection),
                        default => null,
                    };
                })
                ->filter(fn ($result) => $result !== null)
                ->values()
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

    protected function runEloquentStep(array $step): array|string|int|float|null
    {
        $modelClass = $this->resolveModelClass($step['model'] ?? null);

        if ($modelClass) {
            /** @var Model $model */
            $query = $modelClass::query();
        } else {
            $table = $this->resolveTableName($step);

            if (!$table) {
                throw new RuntimeException('Invalid model or table specified in step.');
            }

            $query = DB::table($table);
        }

        $operations = collect($step['operations'] ?? []);
        $result = null;

        foreach ($operations as $operation) {
            $method = $operation['method'] ?? null;
            $arguments = $operation['arguments'] ?? [];

            if (!$method || !method_exists($query, $method)) {
                continue;
            }

            $result = $query->{$method}(...$arguments);

            if (!$result instanceof QueryBuilder && !($result instanceof EloquentBuilder)) {
                return $this->normalizeResult($result);
            }

            $query = $result;
        }

        $columns = $step['columns'] ?? ['*'];

        return $query->get($columns)->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $metadata
     * @return array<string, class-string<Model>>
     */
    protected function buildModelMap(array $metadata): array
    {
        $map = [];

        foreach ($metadata as $table) {
            $name = $table['name'] ?? null;
            $model = $table['model'] ?? null;

            if (!is_string($name) || !is_string($model)) {
                continue;
            }

            $normalizedModel = trim($model);

            if ($normalizedModel === '') {
                continue;
            }

            $map[$this->normalizeModelLookupKey($name)] = $normalizedModel;
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $metadata
     * @return array<string, string>
     */
    protected function buildTableMap(array $metadata): array
    {
        $map = [];

        foreach ($metadata as $table) {
            $name = $table['name'] ?? null;

            if (!is_string($name)) {
                continue;
            }

            $normalized = $this->normalizeModelLookupKey($name);

            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = $name;
        }

        return $map;
    }

    protected function resolveModelClass($model): ?string
    {
        if (!is_string($model)) {
            return null;
        }

        $candidate = trim($model);

        if ($candidate === '') {
            return null;
        }

        if (class_exists($candidate) && is_subclass_of($candidate, Model::class)) {
            return $candidate;
        }

        if (empty($this->metadataModelMap)) {
            $this->getMetadata();
        }

        $normalized = $this->normalizeModelLookupKey($candidate);
        $resolved = $this->metadataModelMap[$normalized] ?? null;

        if (is_string($resolved) && class_exists($resolved) && is_subclass_of($resolved, Model::class)) {
            return $resolved;
        }

        return null;
    }

    protected function resolveTableName(array $step): ?string
    {
        $candidate = null;

        $model = $step['model'] ?? null;
        $table = $step['table'] ?? null;

        if (is_string($table) && trim($table) !== '') {
            $candidate = $table;
        } elseif (is_string($model) && trim($model) !== '') {
            $candidate = $model;
        }

        if ($candidate === null) {
            return null;
        }

        if (empty($this->metadataTableMap)) {
            $this->getMetadata();
        }

        $normalized = $this->normalizeModelLookupKey($candidate);

        return $this->metadataTableMap[$normalized] ?? null;
    }

    protected function normalizeModelLookupKey(string $value): string
    {
        return strtolower(trim($value));
    }

    protected function runRawStep(array $step, ?ConnectionInterface $connection = null): array
    {
        $query = trim($step['query'] ?? '');

        if (!$query) {
            throw new RuntimeException('Raw query is missing.');
        }

        if (!Str::startsWith(strtolower($query), 'select')) {
            throw new RuntimeException('Only SELECT raw queries are allowed.');
        }

        $bindings = $step['bindings'] ?? [];

        if (!is_array($bindings)) {
            throw new RuntimeException('Bindings must be an array.');
        }

        $connection ??= $this->getConnection();

        return collect($connection->select($query, $bindings))
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    protected function getConnection(): ConnectionInterface
    {
        $connection = $this->config['connection'] ?? null;

        return $connection ? DB::connection($connection) : DB::connection();
    }

    protected function normalizeResult($result): array|string|int|float|null
    {
        if ($result instanceof Collection) {
            return $result->toArray();
        }

        if ($result instanceof Model) {
            return $result->toArray();
        }

        return $result;
    }
}
