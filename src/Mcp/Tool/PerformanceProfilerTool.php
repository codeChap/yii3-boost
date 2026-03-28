<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Performance Profiler Tool
 *
 * Provides database performance analysis including:
 * - EXPLAIN query plans with driver-specific formatting
 * - Table-level index analysis and missing index detection
 * - Per-table overview with row counts and index coverage
 */
final class PerformanceProfilerTool extends AbstractTool
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function getName(): string
    {
        return 'performance_profiler';
    }

    public function getDescription(): string
    {
        return 'Analyze query performance with EXPLAIN plans, index coverage, and table statistics';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to EXPLAIN. Returns execution plan with analysis.',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Bound parameters for the SQL query (e.g., {":id": 1})',
                    'additionalProperties' => true,
                ],
                'table' => [
                    'type' => 'string',
                    'description' => 'Table name for index analysis. Used when sql is not provided.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $sql = $arguments['sql'] ?? null;
        $params = $arguments['params'] ?? [];
        $table = $arguments['table'] ?? null;

        try {
            if ($sql !== null && $sql !== '') {
                return $this->explainQuery($sql, $params);
            }

            if ($table !== null && $table !== '') {
                return $this->analyzeTable($table);
            }

            return $this->getOverview();
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run EXPLAIN on a query and provide driver-specific analysis.
     *
     * @param string $sql The SQL query to explain
     * @param array $params Bound parameters
     * @return array EXPLAIN results with warnings
     */
    private function explainQuery(string $sql, array $params = []): array
    {
        $driver = $this->db->getDriverName();

        $explainPrefix = match ($driver) {
            'mysql' => 'EXPLAIN ',
            'pgsql' => 'EXPLAIN ',
            'sqlite' => 'EXPLAIN QUERY PLAN ',
            default => 'EXPLAIN ',
        };

        $command = $this->db->createCommand($explainPrefix . $sql);
        foreach ($params as $name => $value) {
            $command->bindValue($name, $value);
        }

        $rows = $command->queryAll();
        $warnings = $this->analyzeExplainRows($rows, $driver);

        return [
            'driver' => $driver,
            'query' => $sql,
            'params' => $params,
            'explain' => $rows,
            'warnings' => $warnings,
            'warning_count' => count($warnings),
        ];
    }

    /**
     * Analyze EXPLAIN output rows for performance issues, based on the database driver.
     *
     * @param array $rows EXPLAIN result rows
     * @param string $driver Database driver name
     * @return array List of warning strings
     */
    private function analyzeExplainRows(array $rows, string $driver): array
    {
        $warnings = [];

        match ($driver) {
            'mysql' => $this->analyzeMysqlExplain($rows, $warnings),
            'pgsql' => $this->analyzePgsqlExplain($rows, $warnings),
            'sqlite' => $this->analyzeSqliteExplain($rows, $warnings),
            default => null,
        };

        return $warnings;
    }

    /**
     * Analyze MySQL EXPLAIN output for common performance problems.
     */
    private function analyzeMysqlExplain(array $rows, array &$warnings): void
    {
        foreach ($rows as $i => $row) {
            $table = $row['table'] ?? $row['TABLE'] ?? 'unknown';
            $type = $row['type'] ?? $row['TYPE'] ?? '';
            $extra = $row['Extra'] ?? $row['extra'] ?? $row['EXTRA'] ?? '';
            $key = $row['key'] ?? $row['KEY'] ?? null;
            $possibleKeys = $row['possible_keys'] ?? $row['POSSIBLE_KEYS'] ?? null;
            $rowsEstimate = $row['rows'] ?? $row['ROWS'] ?? null;

            // Full table scan detection
            if (strtolower($type) === 'all') {
                $msg = "Full table scan on `{$table}`";
                if ($rowsEstimate !== null) {
                    $msg .= " (~{$rowsEstimate} rows)";
                }
                if ($possibleKeys === null || $possibleKeys === '') {
                    $msg .= ' — no usable index found';
                }
                $warnings[] = $msg;
            }

            // Filesort detection
            if (stripos($extra, 'Using filesort') !== false) {
                $warnings[] = "Filesort detected on `{$table}` — consider adding an index that covers the ORDER BY columns";
            }

            // Temporary table detection
            if (stripos($extra, 'Using temporary') !== false) {
                $warnings[] = "Temporary table used for `{$table}` — consider optimizing GROUP BY or DISTINCT";
            }

            // No index used at all
            if ($key === null && strtolower($type) !== 'all') {
                if (!empty($possibleKeys)) {
                    $warnings[] = "No index chosen for `{$table}` despite possible keys: {$possibleKeys}";
                }
            }
        }
    }

    /**
     * Analyze PostgreSQL EXPLAIN output for sequential scans.
     */
    private function analyzePgsqlExplain(array $rows, array &$warnings): void
    {
        foreach ($rows as $row) {
            // PostgreSQL EXPLAIN returns a single column, typically 'QUERY PLAN'
            $plan = $row['QUERY PLAN'] ?? $row['query plan'] ?? reset($row);
            if (!is_string($plan)) {
                continue;
            }

            if (stripos($plan, 'Seq Scan') !== false) {
                // Try to extract the table name from the plan line
                if (preg_match('/Seq Scan on (\S+)/', $plan, $matches)) {
                    $warnings[] = "Sequential scan on `{$matches[1]}` — consider adding an index";
                } else {
                    $warnings[] = "Sequential scan detected — consider adding an index";
                }
            }
        }
    }

    /**
     * Analyze SQLite EXPLAIN QUERY PLAN output for table scans without index usage.
     */
    private function analyzeSqliteExplain(array $rows, array &$warnings): void
    {
        foreach ($rows as $row) {
            $detail = $row['detail'] ?? $row['DETAIL'] ?? reset($row);
            if (!is_string($detail)) {
                continue;
            }

            // SQLite reports "SCAN <table>" when no index is used,
            // vs "SEARCH <table> USING INDEX" when an index is used.
            if (stripos($detail, 'SCAN') !== false && stripos($detail, 'USING INDEX') === false) {
                if (preg_match('/SCAN\s+(?:TABLE\s+)?(\S+)/i', $detail, $matches)) {
                    $warnings[] = "Full table scan on `{$matches[1]}` without index — consider adding an index";
                } else {
                    $warnings[] = "Full table scan detected without index — consider adding an index";
                }
            }
        }
    }

    /**
     * Analyze a single table: indexes, foreign keys, and missing index detection.
     *
     * @param string $table Table name to analyze
     * @return array Analysis results
     */
    private function analyzeTable(string $table): array
    {
        $schema = $this->db->getSchema();
        $tableSchema = $schema->getTableSchema($table);

        if ($tableSchema === null) {
            return [
                'error' => "Table `{$table}` not found",
            ];
        }

        // Gather column information
        $columns = [];
        foreach ($tableSchema->getColumns() as $name => $column) {
            $columns[] = $name;
        }

        $primaryKey = $tableSchema->getPrimaryKey();

        // Gather indexes
        $indexes = [];
        $indexedColumnSets = [];
        foreach ($tableSchema->getIndexes() as $index) {
            $indexes[] = [
                'name' => $index->name,
                'columns' => $index->columnNames,
                'is_unique' => $index->isUnique,
                'is_primary' => $index->isPrimaryKey,
            ];

            // Track all columns that appear as the first column of any index
            // (for missing-index detection, a column is "covered" if it is the
            // leading column of at least one index)
            foreach ($index->columnNames as $col) {
                $indexedColumnSets[$col] = true;
            }
        }

        // Gather foreign key columns
        $fkColumns = [];
        foreach ($tableSchema->getForeignKeys() as $fk) {
            foreach ($fk->columnNames as $col) {
                $fkColumns[$col] = $fk->foreignTableName;
            }
        }

        // Also detect _id suffix convention columns as potential FK columns
        foreach ($columns as $col) {
            if (str_ends_with($col, '_id') && !isset($fkColumns[$col])) {
                $fkColumns[$col] = '(convention: _id suffix)';
            }
        }

        // Find FK columns that lack a covering index
        $missingIndexes = [];
        foreach ($fkColumns as $col => $referencedTable) {
            // Skip if this column is part of the primary key
            if (in_array($col, $primaryKey, true)) {
                continue;
            }

            // Check if any index has this column as its first (leading) column
            $hasCoveringIndex = false;
            foreach ($tableSchema->getIndexes() as $index) {
                if (count($index->columnNames) > 0 && $index->columnNames[0] === $col) {
                    $hasCoveringIndex = true;
                    break;
                }
            }

            if (!$hasCoveringIndex) {
                $missingIndexes[] = [
                    'column' => $col,
                    'referenced_table' => $referencedTable,
                    'suggestion' => "CREATE INDEX idx_{$table}_{$col} ON {$table} ({$col})",
                ];
            }
        }

        return [
            'table' => $table,
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'indexes' => $indexes,
            'index_count' => count($indexes),
            'foreign_key_columns' => $fkColumns,
            'missing_indexes' => $missingIndexes,
            'missing_index_count' => count($missingIndexes),
        ];
    }

    /**
     * Get an overview of all tables with row counts, index counts, and missing indexes.
     *
     * @return array Overview data
     */
    private function getOverview(): array
    {
        $schema = $this->db->getSchema();
        $tableNames = $schema->getTableNames();
        $quoter = $this->db->getQuoter();

        $tables = [];
        $totalMissing = 0;

        foreach ($tableNames as $tableName) {
            $tableSchema = $schema->getTableSchema($tableName);
            if ($tableSchema === null) {
                continue;
            }

            // Row count
            $quotedTable = $quoter->quoteTableName($tableName);
            $rowCount = $this->db
                ->createCommand("SELECT COUNT(*) FROM {$quotedTable}")
                ->queryScalar();

            // Index count
            $indexCount = count($tableSchema->getIndexes());

            // FK columns and missing indexes (lightweight version)
            $fkColumns = [];
            foreach ($tableSchema->getForeignKeys() as $fk) {
                foreach ($fk->columnNames as $col) {
                    $fkColumns[$col] = true;
                }
            }

            // Also consider _id convention
            foreach ($tableSchema->getColumns() as $colName => $column) {
                if (str_ends_with($colName, '_id')) {
                    $fkColumns[$colName] = true;
                }
            }

            $primaryKey = $tableSchema->getPrimaryKey();
            $missingCount = 0;

            foreach (array_keys($fkColumns) as $col) {
                if (in_array($col, $primaryKey, true)) {
                    continue;
                }

                $hasCoveringIndex = false;
                foreach ($tableSchema->getIndexes() as $index) {
                    if (count($index->columnNames) > 0 && $index->columnNames[0] === $col) {
                        $hasCoveringIndex = true;
                        break;
                    }
                }

                if (!$hasCoveringIndex) {
                    $missingCount++;
                }
            }

            $totalMissing += $missingCount;

            $tables[] = [
                'table' => $tableName,
                'row_count' => (int) $rowCount,
                'column_count' => count($tableSchema->getColumns()),
                'index_count' => $indexCount,
                'missing_indexes' => $missingCount,
            ];
        }

        return [
            'driver' => $this->db->getDriverName(),
            'table_count' => count($tables),
            'total_missing_indexes' => $totalMissing,
            'tables' => $tables,
        ];
    }
}
