<?php

namespace LaraGrep\Metadata;

use Illuminate\Database\ConnectionResolverInterface;
use function collect;

class SchemaMetadataLoader
{
    /**
     * @param array<int, string> $excludeTables
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected ?string $connection = null,
        protected array $excludeTables = []
    ) {
    }

    public function load(?string $connection = null, ?array $excludeTables = null): array
    {
        $connectionName = $connection ?? $this->connection;
        $excludeTables = $excludeTables ?? $this->excludeTables;

        $connection = $this->resolver->connection($connectionName);
        $database = $connection->getDatabaseName();

        if (!$database) {
            return [];
        }

        $tables = collect($connection->select(
            'SELECT TABLE_NAME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$database]
        ));

        $excluded = collect($excludeTables)
            ->filter()
            ->map(fn ($name) => strtolower((string) $name))
            ->unique()
            ->values();

        $tables = $tables->filter(function ($table) use ($excluded) {
            $tableName = $table->TABLE_NAME ?? $table->table_name ?? null;

            if (!$tableName) {
                return false;
            }

            if ($excluded->contains(strtolower($tableName))) {
                return false;
            }

            return true;
        });

        $columns = collect($connection->select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database]
        ));

        $tableData = $tables->mapWithKeys(function ($table) {
            $tableName = $table->TABLE_NAME ?? $table->table_name ?? null;

            if (!$tableName) {
                return [];
            }

            return [
                $tableName => [
                    'name' => $tableName,
                    'description' => (string) ($table->TABLE_COMMENT ?? $table->table_comment ?? ''),
                    'columns' => [],
                ],
            ];
        })->all();

        $columns->each(function ($column) use (&$tableData) {
            $tableName = $column->TABLE_NAME ?? $column->table_name ?? null;
            $columnName = $column->COLUMN_NAME ?? $column->column_name ?? null;

            if (!$tableName || !$columnName || !isset($tableData[$tableName])) {
                return;
            }

            $tableData[$tableName]['columns'][] = [
                'name' => $columnName,
                'type' => (string) ($column->COLUMN_TYPE ?? $column->column_type ?? ''),
                'description' => (string) ($column->COLUMN_COMMENT ?? $column->column_comment ?? ''),
            ];
        });

        return collect($tableData)->map(function ($table) {
            $table['columns'] = collect($table['columns'])
                ->sortBy('name')
                ->values()
                ->all();

            return $table;
        })->values()->all();
    }
}
