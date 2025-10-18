<?php

namespace LaraGrep\Services;

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

                $tableDescription = trim(($table['description'] ?? '') ?: '');

                return sprintf(
                    "Table %s%s\n%s",
                    $table['name'],
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

    public function answerQuestion(string $question): array
    {
        $prompt = $this->buildPrompt($question);
        $response = $this->callModel($prompt);
        $parsed = $this->interpretResponse($response);
        $steps = $parsed['steps'];

        return [
            'summary' => $parsed['summary'],
            'steps' => $steps,
            'results' => $this->executeSteps($steps),
        ];
    }

    protected function getMetadata(): array
    {
        if ($this->cachedMetadata !== null) {
            return $this->cachedMetadata;
        }

        $configured = $this->config['metadata'] ?? [];
        $loaded = $this->metadataLoader->load();

        return $this->cachedMetadata = array_values(array_merge($loaded, $configured));
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

    protected function executeSteps(array $steps): array
    {
        return collect($steps)
            ->map(function (array $step) {
                return match ($step['type'] ?? null) {
                    'eloquent' => $this->runEloquentStep($step),
                    'raw' => $this->runRawStep($step),
                    default => null,
                };
            })
            ->filter(fn ($result) => $result !== null)
            ->values()
            ->all();
    }

    protected function runEloquentStep(array $step): array|string|int|float|null
    {
        $modelClass = $step['model'] ?? null;

        if (!$modelClass || !class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException('Invalid model specified in step.');
        }

        /** @var Model $model */
        $query = $modelClass::query();

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

    protected function runRawStep(array $step): array
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

        return collect(DB::select($query, $bindings))
            ->map(fn ($row) => (array) $row)
            ->all();
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
