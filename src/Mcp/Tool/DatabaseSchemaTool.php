<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Database Schema Tool
 *
 * Provides complete database introspection including:
 * - Tables with row counts
 * - Table schemas (columns, types, defaults)
 * - Indexes and constraints
 * - Foreign key relationships
 */
final class DatabaseSchemaTool extends AbstractTool
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function getName(): string
    {
        return 'database_schema';
    }

    public function getDescription(): string
    {
        return 'Inspect database schema including tables, columns, indexes, and foreign keys';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Specific table to inspect (optional — omit to list all tables)',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: tables, schema, indexes, foreign_keys, all (default: all relevant sections)',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $table = $arguments['table'] ?? null;
        $include = $arguments['include'] ?? [];

        // Normalize include list — empty means "all"
        $includeAll = $include === [] || in_array('all', $include, true);

        try {
            $result = [
                'connection' => $this->getConnectionInfo(),
            ];

            if ($table === null) {
                // No table specified — list all tables with row counts
                if ($includeAll || in_array('tables', $include, true)) {
                    $result['tables'] = $this->listTables();
                }
            } else {
                // Specific table requested
                $schema = $this->db->getSchema();
                $tableSchema = $schema->getTableSchema($table);

                if ($tableSchema === null) {
                    return [
                        'error' => "Table '{$table}' not found",
                        'available_tables' => $schema->getTableNames(),
                    ];
                }

                if ($includeAll || in_array('schema', $include, true)) {
                    $result['schema'] = $this->getTableSchema($tableSchema);
                }

                if ($includeAll || in_array('indexes', $include, true)) {
                    $result['indexes'] = $this->getTableIndexes($tableSchema);
                }

                if ($includeAll || in_array('foreign_keys', $include, true)) {
                    $result['foreign_keys'] = $this->getTableForeignKeys($tableSchema);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ];
        }
    }

    /**
     * Get sanitized connection info including driver name.
     */
    private function getConnectionInfo(): array
    {
        return $this->sanitize([
            'driver' => $this->db->getDriverName(),
        ]);
    }

    /**
     * List all tables with their row counts.
     *
     * @return array<int, array{name: string, rows: int|string}>
     */
    private function listTables(): array
    {
        $schema = $this->db->getSchema();
        $tableNames = $schema->getTableNames();
        $quoter = $this->db->getQuoter();
        $tables = [];

        sort($tableNames);

        foreach ($tableNames as $name) {
            $quotedName = $quoter->quoteTableName($name);
            try {
                $rowCount = (int) $this->db
                    ->createCommand("SELECT COUNT(*) FROM {$quotedName}")
                    ->queryScalar();
            } catch (\Throwable) {
                $rowCount = 'error';
            }

            $tables[] = [
                'name' => $name,
                'rows' => $rowCount,
            ];
        }

        return $tables;
    }

    /**
     * Get detailed schema for a single table.
     */
    private function getTableSchema(\Yiisoft\Db\Schema\TableSchemaInterface $tableSchema): array
    {
        $columns = [];

        foreach ($tableSchema->getColumns() as $name => $column) {
            $columnInfo = [
                'name' => $name,
                'type' => $column->getType(),
                'db_type' => $column->getDbType(),
                'size' => $column->getSize(),
                'scale' => $column->getScale(),
                'nullable' => $column->isNotNull() === true ? false : true,
                'default' => $column->getDefaultValue(),
                'auto_increment' => $column->isAutoIncrement(),
                'primary_key' => $column->isPrimaryKey(),
                'unique' => $column->isUnique(),
                'unsigned' => $column->isUnsigned(),
                'comment' => $column->getComment(),
            ];

            $columns[] = $columnInfo;
        }

        return [
            'table' => $tableSchema->getName(),
            'primary_key' => $tableSchema->getPrimaryKey(),
            'comment' => $tableSchema->getComment(),
            'column_count' => count($columns),
            'columns' => $columns,
        ];
    }

    /**
     * Get indexes for a table.
     *
     * @return array<int, array{name: string, columns: array, is_unique: bool, is_primary: bool}>
     */
    private function getTableIndexes(\Yiisoft\Db\Schema\TableSchemaInterface $tableSchema): array
    {
        $indexes = [];

        foreach ($tableSchema->getIndexes() as $index) {
            $indexes[] = [
                'name' => $index->name,
                'columns' => $index->columnNames,
                'is_unique' => $index->isUnique,
                'is_primary' => $index->isPrimaryKey,
            ];
        }

        return $indexes;
    }

    /**
     * Get foreign keys for a table.
     *
     * @return array<int, array{name: string, columns: array, foreign_table: string, foreign_columns: array, on_delete: ?string, on_update: ?string}>
     */
    private function getTableForeignKeys(\Yiisoft\Db\Schema\TableSchemaInterface $tableSchema): array
    {
        $foreignKeys = [];

        foreach ($tableSchema->getForeignKeys() as $fk) {
            $foreignKeys[] = [
                'name' => $fk->name,
                'columns' => $fk->columnNames,
                'foreign_schema' => $fk->foreignSchemaName,
                'foreign_table' => $fk->foreignTableName,
                'foreign_columns' => $fk->foreignColumnNames,
                'on_delete' => $fk->onDelete,
                'on_update' => $fk->onUpdate,
            ];
        }

        return $foreignKeys;
    }
}
