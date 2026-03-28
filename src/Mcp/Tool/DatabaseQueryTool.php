<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Database Query Tool
 *
 * Execute read-only SQL queries against the database and return results.
 * Intended for development use — allows AI assistants to explore
 * and query data during debugging and development.
 *
 * SECURITY: Only SELECT queries are permitted. This tool is disabled by
 * default in configuration and must be explicitly enabled.
 */
final class DatabaseQueryTool extends AbstractTool
{
    /**
     * Maximum number of rows that can be returned.
     */
    private const MAX_LIMIT = 1000;

    /**
     * Default number of rows returned when no limit is specified.
     */
    private const DEFAULT_LIMIT = 100;

    /**
     * SQL keywords that are forbidden (write/DDL/DCL operations).
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'TRUNCATE',
        'CREATE',
        'GRANT',
        'REVOKE',
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
    ) {
    }

    public function getName(): string
    {
        return 'database_query';
    }

    public function getDescription(): string
    {
        return 'Execute SQL queries against the database and return results. '
            . 'IMPORTANT: Always use database_schema tool first to inspect table columns before writing queries. '
            . 'Do not guess column names.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'SQL query to execute',
                ],
                'params' => [
                    'type' => 'object',
                    'description' => 'Bound parameters for the query (e.g., {":id": 1})',
                    'additionalProperties' => true,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum rows to return (default: 100, max: 1000)',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $sql = trim($arguments['sql'] ?? '');
        $params = $arguments['params'] ?? [];
        $limit = min(
            max(1, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)),
            self::MAX_LIMIT,
        );

        if ($sql === '') {
            return [
                'error' => 'SQL query cannot be empty.',
            ];
        }

        // Enforce SELECT-only queries
        if (!$this->isSelectQuery($sql)) {
            $firstWord = strtoupper(strtok($sql, " \t\r\n") ?: '');
            return [
                'error' => "Only SELECT queries are allowed. Detected: {$firstWord}. "
                    . 'INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, GRANT, and REVOKE are forbidden.',
            ];
        }

        // Ensure a LIMIT clause is present to prevent unbounded result sets
        $sql = $this->ensureLimit($sql, $limit);

        // Build connection info for context
        $connectionInfo = $this->getConnectionInfo();

        try {
            $command = $this->db->createCommand($sql);

            // Bind parameters
            foreach ($params as $name => $value) {
                $command->bindValue($name, $value);
            }

            $rows = $command->queryAll();

            // Sanitize output to redact sensitive values
            $sanitizedRows = array_map(
                fn(array $row): array => $this->sanitize($row),
                $rows,
            );

            return [
                'connection' => $connectionInfo,
                'sql' => $sql,
                'params' => $params,
                'row_count' => count($sanitizedRows),
                'rows' => $sanitizedRows,
            ];
        } catch (\Throwable $e) {
            // On error, provide schema hints for tables referenced in the query
            $schemaHints = $this->getColumnsForTablesInQuery($sql);

            return [
                'connection' => $connectionInfo,
                'sql' => $sql,
                'params' => $params,
                'error' => $e->getMessage(),
                'schema_hints' => $schemaHints,
                'hint' => 'Use the database_schema tool to inspect table structure before querying.',
            ];
        }
    }

    /**
     * Check if the SQL statement is a SELECT query.
     *
     * Trims whitespace and checks that the first keyword is SELECT.
     */
    private function isSelectQuery(string $sql): bool
    {
        $normalized = ltrim($sql);

        // Check against forbidden keywords first
        $firstWord = strtoupper(strtok($normalized, " \t\r\n") ?: '');

        if (in_array($firstWord, self::FORBIDDEN_KEYWORDS, true)) {
            return false;
        }

        // Must start with SELECT (or WITH for CTEs that resolve to SELECT)
        return in_array($firstWord, ['SELECT', 'WITH'], true);
    }

    /**
     * Ensure the SQL query has a LIMIT clause.
     *
     * If no LIMIT is present, one is appended based on the requested limit.
     * This prevents accidentally returning millions of rows.
     */
    private function ensureLimit(string $sql, int $limit): string
    {
        // Strip trailing semicolons for analysis
        $trimmed = rtrim($sql, "; \t\n\r");

        // Check if a LIMIT clause already exists (case-insensitive)
        if (preg_match('/\bLIMIT\s+\d+/i', $trimmed)) {
            return $trimmed;
        }

        return $trimmed . ' LIMIT ' . $limit;
    }

    /**
     * Extract table names from the SQL query and return their column names.
     *
     * This is used to provide helpful schema hints when a query fails,
     * so the AI assistant can see available columns and correct its query.
     */
    private function getColumnsForTablesInQuery(string $sql): array
    {
        $tables = $this->extractTableNames($sql);
        $hints = [];

        $schema = $this->db->getSchema();

        foreach ($tables as $table) {
            try {
                $tableSchema = $schema->getTableSchema($table);
                if ($tableSchema !== null) {
                    $quotedName = $this->db->getQuoter()->quoteTableName($table);
                    $hints[$quotedName] = $tableSchema->getColumnNames();
                }
            } catch (\Throwable) {
                // Skip tables whose schema cannot be resolved
            }
        }

        return $hints;
    }

    /**
     * Extract table names from FROM and JOIN clauses in the SQL query.
     *
     * Uses regex to find identifiers after FROM and JOIN keywords.
     *
     * @return array<string> Table names found in the query
     */
    private function extractTableNames(string $sql): array
    {
        $tables = [];

        // Match table names after FROM and JOIN keywords
        // Handles: FROM table, FROM `table`, FROM schema.table, JOIN table, etc.
        if (preg_match_all(
            '/\b(?:FROM|JOIN)\s+[`"\[]?(\w+)[`"\]]?(?:\s*\.\s*[`"\[]?(\w+)[`"\]]?)?/i',
            $sql,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                // If schema.table format, use the table part; otherwise use the first match
                $tables[] = !empty($match[2]) ? $match[2] : $match[1];
            }
        }

        return array_unique($tables);
    }

    /**
     * Build connection information from the driver name.
     *
     * Provides context about which database connection is being queried
     * without exposing sensitive DSN details.
     */
    private function getConnectionInfo(): array
    {
        try {
            $driverName = $this->db->getDriverName();
        } catch (\Throwable) {
            $driverName = 'unknown';
        }

        return [
            'driver' => $driverName,
        ];
    }
}
