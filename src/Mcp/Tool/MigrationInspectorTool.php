<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Config\ConfigInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Migration Inspector Tool
 *
 * Inspects database migrations including:
 * - Migration status summary (applied, pending, total)
 * - Applied migration history with timestamps
 * - Pending migration discovery
 * - Individual migration source code viewing
 */
final class MigrationInspectorTool extends AbstractTool
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ConfigInterface $config,
    ) {
    }

    public function getName(): string
    {
        return 'migration_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect database migrations: status, history, pending, and source code';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'migration' => [
                    'type' => 'string',
                    'description' => 'Specific migration name to view details/source. Omit for overview.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: status, history, pending, source, all. '
                        . 'Defaults to [status, history].',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Limit number of history/pending results. Default: 50.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $migration = $arguments['migration'] ?? null;
        $include = $arguments['include'] ?? ['status', 'history'];
        $limit = $arguments['limit'] ?? 50;

        $includeAll = in_array('all', $include, true);

        try {
            // If a specific migration is requested, view it
            if ($migration !== null) {
                return $this->viewMigration($migration);
            }

            $result = [];

            if ($includeAll || in_array('status', $include, true)) {
                $result['status'] = $this->getStatus();
            }

            if ($includeAll || in_array('history', $include, true)) {
                $result['history'] = $this->getHistory($limit);
            }

            if ($includeAll || in_array('pending', $include, true)) {
                $result['pending'] = $this->getPending($limit);
            }

            if ($includeAll || in_array('source', $include, true)) {
                $result['migration_paths'] = $this->getMigrationPaths();
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
     * Get migration status summary.
     *
     * @return array{applied: int, pending: int, total: int, last_applied: ?array}
     */
    private function getStatus(): array
    {
        $appliedCount = 0;
        $lastApplied = null;

        if ($this->migrationTableExists()) {
            $tableName = $this->getMigrationTableName();
            $quotedTable = $this->db->getQuoter()->quoteTableName($tableName);

            $appliedCount = (int) $this->db
                ->createCommand("SELECT COUNT(*) FROM {$quotedTable}")
                ->queryScalar();

            $rows = $this->db
                ->createCommand(
                    "SELECT name, apply_time FROM {$quotedTable} ORDER BY apply_time DESC LIMIT 1"
                )
                ->queryAll();

            if ($rows !== []) {
                $lastApplied = [
                    'version' => $rows[0]['name'],
                    'applied_at' => date('Y-m-d H:i:s', (int) $rows[0]['apply_time']),
                ];
            }
        }

        $allMigrations = $this->scanAllMigrations();
        $total = count($allMigrations);
        $pending = $total - $appliedCount;

        // Pending count should not be negative (e.g. if migrations were removed from disk)
        if ($pending < 0) {
            $pending = 0;
        }

        return [
            'applied' => $appliedCount,
            'pending' => $pending,
            'total' => $total,
            'last_applied' => $lastApplied,
        ];
    }

    /**
     * Get applied migration history ordered by most recent first.
     *
     * @return array<int, array{version: string, applied_at: string}>
     */
    private function getHistory(int $limit): array
    {
        if (!$this->migrationTableExists()) {
            return [];
        }

        $tableName = $this->getMigrationTableName();
        $quotedTable = $this->db->getQuoter()->quoteTableName($tableName);

        $rows = $this->db
            ->createCommand(
                "SELECT name, apply_time FROM {$quotedTable} ORDER BY apply_time DESC LIMIT :limit",
                [':limit' => $limit],
            )
            ->queryAll();

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'version' => $row['name'],
                'applied_at' => date('Y-m-d H:i:s', (int) $row['apply_time']),
            ];
        }

        return $history;
    }

    /**
     * Get pending (unapplied) migrations.
     *
     * @return array<int, array{version: string, file: ?string}>
     */
    private function getPending(int $limit): array
    {
        $appliedNames = [];

        if ($this->migrationTableExists()) {
            $tableName = $this->getMigrationTableName();
            $quotedTable = $this->db->getQuoter()->quoteTableName($tableName);

            $rows = $this->db
                ->createCommand("SELECT name FROM {$quotedTable}")
                ->queryAll();

            foreach ($rows as $row) {
                $appliedNames[$row['name']] = true;
            }
        }

        $allMigrations = $this->scanAllMigrations();
        $pending = [];

        foreach ($allMigrations as $migration) {
            if (!isset($appliedNames[$migration['class']])) {
                $pending[] = [
                    'version' => $migration['class'],
                    'file' => $migration['file'],
                ];

                if (count($pending) >= $limit) {
                    break;
                }
            }
        }

        return $pending;
    }

    /**
     * View details and source code for a specific migration.
     *
     * @return array{name: string, applied: bool, applied_at: ?string, file: ?string, source: ?string}
     */
    private function viewMigration(string $name): array
    {
        $result = [
            'name' => $name,
            'applied' => false,
            'applied_at' => null,
            'file' => null,
            'source' => null,
        ];

        // Check if applied
        if ($this->migrationTableExists()) {
            $tableName = $this->getMigrationTableName();
            $quotedTable = $this->db->getQuoter()->quoteTableName($tableName);

            $rows = $this->db
                ->createCommand(
                    "SELECT name, apply_time FROM {$quotedTable} WHERE name = :name",
                    [':name' => $name],
                )
                ->queryAll();

            if ($rows !== []) {
                $result['applied'] = true;
                $result['applied_at'] = date('Y-m-d H:i:s', (int) $rows[0]['apply_time']);
            }
        }

        // Find source file
        $file = $this->findMigrationFile($name);
        if ($file !== null) {
            $result['file'] = $file;
            $source = @file_get_contents($file);
            if ($source !== false) {
                $result['source'] = $source;
            }
        }

        return $result;
    }

    /**
     * Get configured migration source paths.
     *
     * @return array{source_paths: array, source_namespaces: array}
     */
    private function getMigrationPaths(): array
    {
        $sourcePaths = [];
        $sourceNamespaces = [];

        try {
            $params = $this->config->get('params');
            $migrationConfig = $params['yiisoft/db-migration'] ?? [];

            $sourcePaths = $migrationConfig['sourcePaths'] ?? [];
            $sourceNamespaces = $migrationConfig['sourceNamespaces'] ?? [];
        } catch (\Throwable) {
            // yiisoft/db-migration may not be configured
        }

        return [
            'source_paths' => $sourcePaths,
            'source_namespaces' => $sourceNamespaces,
        ];
    }

    /**
     * Scan a migration directory for migration class files.
     *
     * Matches both Yii3 naming (M[0-9]{12}*.php) and legacy (m[0-9]{6}_[0-9]{6}_*.php).
     *
     * @return array<int, string> Class names (without .php extension)
     */
    private function scanMigrationDirectory(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $classes = [];
        $files = @scandir($path);

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            $basename = substr($file, 0, -4);

            // Yii3 pattern: M followed by 12+ digits then optional description
            // e.g. M260228193504CreateUserTable
            if (preg_match('/^M\d{12}/', $basename)) {
                $classes[] = $basename;
                continue;
            }

            // Legacy Yii2 pattern: m followed by 6digits_6digits_description
            // e.g. m260228_193504_create_user_table
            if (preg_match('/^m\d{6}_\d{6}_/', $basename)) {
                $classes[] = $basename;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * Get the migration history table name.
     */
    private function getMigrationTableName(): string
    {
        return 'migration';
    }

    /**
     * Check if the migration history table exists.
     */
    private function migrationTableExists(): bool
    {
        try {
            $tableName = $this->getMigrationTableName();
            $tableSchema = $this->db->getSchema()->getTableSchema($tableName);

            return $tableSchema !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Scan all configured migration paths and return all discovered migrations.
     *
     * @return array<int, array{class: string, file: string}>
     */
    private function scanAllMigrations(): array
    {
        $migrations = [];
        $paths = $this->getSourcePaths();

        foreach ($paths as $path) {
            $classNames = $this->scanMigrationDirectory($path);

            foreach ($classNames as $className) {
                $migrations[] = [
                    'class' => $className,
                    'file' => $path . DIRECTORY_SEPARATOR . $className . '.php',
                ];
            }
        }

        // Sort by class name to maintain consistent ordering
        usort($migrations, fn(array $a, array $b) => strcmp($a['class'], $b['class']));

        return $migrations;
    }

    /**
     * Resolve all filesystem paths where migrations may live.
     *
     * Includes both sourcePaths and paths derived from sourceNamespaces.
     *
     * @return array<int, string>
     */
    private function getSourcePaths(): array
    {
        $paths = [];

        try {
            $params = $this->config->get('params');
            $migrationConfig = $params['yiisoft/db-migration'] ?? [];

            // Direct source paths
            $sourcePaths = $migrationConfig['sourcePaths'] ?? [];
            foreach ($sourcePaths as $sourcePath) {
                if (is_string($sourcePath) && is_dir($sourcePath)) {
                    $paths[] = $sourcePath;
                }
            }

            // Namespace-based paths: convert namespace to filesystem path via Composer autoloading
            $sourceNamespaces = $migrationConfig['sourceNamespaces'] ?? [];
            foreach ($sourceNamespaces as $namespace) {
                if (!is_string($namespace)) {
                    continue;
                }

                $resolvedPath = $this->resolveNamespacePath($namespace);
                if ($resolvedPath !== null && is_dir($resolvedPath)) {
                    $paths[] = $resolvedPath;
                }
            }
        } catch (\Throwable) {
            // yiisoft/db-migration may not be installed or configured
        }

        return array_unique($paths);
    }

    /**
     * Resolve a namespace to a filesystem path using class autoloading.
     *
     * Creates a dummy class name within the namespace and uses the autoloader
     * to find where that namespace maps on disk.
     */
    private function resolveNamespacePath(string $namespace): ?string
    {
        $namespace = rtrim($namespace, '\\');

        // Try to find a loaded class in this namespace to derive the path
        // Use Composer's autoloader to resolve the namespace
        $autoloaders = spl_autoload_functions();
        if ($autoloaders === false) {
            return null;
        }

        foreach ($autoloaders as $autoloader) {
            // Check for Composer's ClassLoader
            if (is_array($autoloader) && isset($autoloader[0]) && $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                /** @var \Composer\Autoload\ClassLoader $classLoader */
                $classLoader = $autoloader[0];

                // Check PSR-4 prefixes
                $prefixes = $classLoader->getPrefixesPsr4();
                foreach ($prefixes as $prefix => $dirs) {
                    $prefix = rtrim($prefix, '\\');
                    if ($namespace === $prefix) {
                        return $dirs[0] ?? null;
                    }
                    if (str_starts_with($namespace, $prefix . '\\')) {
                        $relPath = str_replace('\\', DIRECTORY_SEPARATOR, substr($namespace, strlen($prefix) + 1));
                        $fullPath = ($dirs[0] ?? '') . DIRECTORY_SEPARATOR . $relPath;
                        if (is_dir($fullPath)) {
                            return $fullPath;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find the source file for a migration by name.
     *
     * Searches all configured migration paths.
     */
    private function findMigrationFile(string $name): ?string
    {
        $paths = $this->getSourcePaths();

        foreach ($paths as $path) {
            $file = $path . DIRECTORY_SEPARATOR . $name . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
