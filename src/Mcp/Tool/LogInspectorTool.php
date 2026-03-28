<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Closure;
use ReflectionClass;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/**
 * Log Inspector Tool
 *
 * Provides unified access to application logs with support for filtering
 * by level, category, time range, and keyword search.
 *
 * Reads log files produced by yiisoft/log-target-file.
 */
final class LogInspectorTool extends AbstractTool
{
    /** Default log file path (alias-based). */
    private const DEFAULT_LOG_FILE = '@runtime/logs/app.log';

    /** Maximum entries to return per request. */
    private const MAX_LIMIT = 1000;

    /** Default limit if none specified. */
    private const DEFAULT_LIMIT = 100;

    /**
     * Regex to parse yiisoft/log-target-file entries.
     * Format: [YYYY-MM-DD HH:MM:SS.microseconds] [category] [level] message
     */
    private const LOG_LINE_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\.\d+)\]\s+\[([^\]]*)\]\s+\[([^\]]*)\]\s+(.*)/s';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Aliases $aliases,
    ) {
    }

    public function getName(): string
    {
        return 'log_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect application logs from configured targets (file) '
            . 'with filtering by level, category, time range, and keywords';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: targets (show configured log targets), read (read and filter log entries)',
                    'enum' => ['targets', 'read'],
                ],
                'levels' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
                    ],
                    'description' => 'PSR-3 log levels to include (default: error, warning)',
                ],
                'categories' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Category patterns to match (supports wildcards). Default: all categories',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum log entries to return (default: 100, max: 1000)',
                    'minimum' => 1,
                    'maximum' => 1000,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Number of entries to skip for pagination (default: 0)',
                    'minimum' => 0,
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Search for keyword in log messages (case-insensitive)',
                ],
                'time_range' => [
                    'type' => 'object',
                    'properties' => [
                        'start' => [
                            'type' => 'integer',
                            'description' => 'Start timestamp (Unix epoch)',
                        ],
                        'end' => [
                            'type' => 'integer',
                            'description' => 'End timestamp (Unix epoch)',
                        ],
                    ],
                    'description' => 'Filter logs within a time range',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'] ?? 'targets';

        return match ($action) {
            'targets' => $this->showTargets(),
            'read' => $this->readLogs($arguments),
            default => ['error' => "Unknown action: {$action}"],
        };
    }

    /**
     * Show configured log targets from DI config.
     */
    private function showTargets(): array
    {
        $targets = [];

        // Check di and di-web for log target definitions
        foreach (['di', 'di-web'] as $group) {
            $definitions = $this->getConfigGroup($group);

            foreach ($definitions as $serviceId => $definition) {
                if (
                    !str_contains(strtolower($serviceId), 'log') &&
                    !str_contains(strtolower($serviceId), 'target')
                ) {
                    continue;
                }

                $targets[] = [
                    'service' => $serviceId,
                    'group' => $group,
                    'definition' => $this->normalizeDefinition($definition),
                ];
            }
        }

        // Also report the resolved log file path
        $logFile = $this->resolveLogFilePath();

        return [
            'log_file' => $logFile,
            'log_file_exists' => $logFile !== null && file_exists($logFile),
            'log_file_size' => $logFile !== null && file_exists($logFile)
                ? $this->formatFileSize(filesize($logFile) ?: 0)
                : null,
            'targets' => $targets,
        ];
    }

    /**
     * Read and filter log entries from the log file.
     */
    private function readLogs(array $arguments): array
    {
        $logFile = $this->resolveLogFilePath();

        if ($logFile === null || !file_exists($logFile)) {
            return [
                'error' => 'Log file not found',
                'expected_path' => $logFile ?? self::DEFAULT_LOG_FILE,
            ];
        }

        if (!is_readable($logFile)) {
            return ['error' => "Log file not readable: {$logFile}"];
        }

        $levels = $arguments['levels'] ?? ['error', 'warning'];
        $categories = $arguments['categories'] ?? [];
        $limit = min($arguments['limit'] ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $offset = $arguments['offset'] ?? 0;
        $search = $arguments['search'] ?? null;
        $timeRange = $arguments['time_range'] ?? null;

        // Read the file and parse entries
        $entries = $this->parseLogFile($logFile);

        // Reverse for reverse chronological order
        $entries = array_reverse($entries);

        // Apply filters
        $filtered = $this->filterEntries($entries, $levels, $categories, $search, $timeRange);

        // Count total before pagination
        $totalFiltered = count($filtered);

        // Apply pagination
        $paginated = array_slice($filtered, $offset, $limit);

        return [
            'file' => $logFile,
            'total_entries_in_file' => count($entries),
            'total_matching' => $totalFiltered,
            'offset' => $offset,
            'limit' => $limit,
            'returned' => count($paginated),
            'filters' => [
                'levels' => $levels,
                'categories' => $categories ?: 'all',
                'search' => $search,
                'time_range' => $timeRange,
            ],
            'entries' => $paginated,
        ];
    }

    /**
     * Parse the log file into structured entries.
     *
     * @return array<array{timestamp: string, level: string, category: string, message: string}>
     */
    private function parseLogFile(string $filePath): array
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $entries = [];
        $currentEntry = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if (preg_match(self::LOG_LINE_PATTERN, $line, $matches)) {
                    // Save the previous entry
                    if ($currentEntry !== null) {
                        $entries[] = $currentEntry;
                    }

                    $currentEntry = [
                        'timestamp' => $matches[1],
                        'category' => $matches[2],
                        'level' => strtolower($matches[3]),
                        'message' => $matches[4],
                    ];
                } elseif ($currentEntry !== null) {
                    // Continuation line — append to current message
                    $currentEntry['message'] .= "\n" . $line;
                }
            }

            // Don't forget the last entry
            if ($currentEntry !== null) {
                $entries[] = $currentEntry;
            }
        } finally {
            fclose($handle);
        }

        return $entries;
    }

    /**
     * Filter parsed log entries by criteria.
     *
     * @param array<array> $entries
     * @param array<string> $levels
     * @param array<string> $categories
     * @param string|null $search
     * @param array|null $timeRange
     * @return array<array>
     */
    private function filterEntries(
        array $entries,
        array $levels,
        array $categories,
        ?string $search,
        ?array $timeRange,
    ): array {
        return array_values(array_filter($entries, function (array $entry) use ($levels, $categories, $search, $timeRange): bool {
            // Filter by level
            if ($levels !== [] && !in_array($entry['level'], $levels, true)) {
                return false;
            }

            // Filter by category patterns
            if ($categories !== [] && !$this->matchesCategory($entry['category'], $categories)) {
                return false;
            }

            // Filter by search keyword
            if ($search !== null && $search !== '') {
                if (stripos($entry['message'], $search) === false && stripos($entry['category'], $search) === false) {
                    return false;
                }
            }

            // Filter by time range
            if ($timeRange !== null) {
                $entryTimestamp = $this->parseTimestamp($entry['timestamp']);
                if ($entryTimestamp === null) {
                    return false;
                }

                if (isset($timeRange['start']) && $entryTimestamp < $timeRange['start']) {
                    return false;
                }

                if (isset($timeRange['end']) && $entryTimestamp > $timeRange['end']) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Check if a category matches any of the given patterns (supports * wildcards).
     *
     * @param string $category
     * @param array<string> $patterns
     */
    private function matchesCategory(string $category, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            // Convert wildcard pattern to regex
            $regex = '/^' . str_replace(
                ['\\*', '\\?'],
                ['.*', '.'],
                preg_quote($pattern, '/'),
            ) . '$/i';

            if (preg_match($regex, $category)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a log timestamp string to Unix epoch.
     */
    private function parseTimestamp(string $timestamp): ?int
    {
        // Format: YYYY-MM-DD HH:MM:SS.microseconds
        $dotPos = strpos($timestamp, '.');
        $dateStr = $dotPos !== false ? substr($timestamp, 0, $dotPos) : $timestamp;

        $time = strtotime($dateStr);

        return $time !== false ? $time : null;
    }

    /**
     * Resolve the log file path from params config or fall back to default.
     */
    private function resolveLogFilePath(): ?string
    {
        $aliasPath = self::DEFAULT_LOG_FILE;

        // Try to get configured path from params
        try {
            $params = $this->config->get('params');
            if (
                is_array($params) &&
                isset($params['yiisoft/log-target-file']['file'])
            ) {
                $aliasPath = (string) $params['yiisoft/log-target-file']['file'];
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        // Resolve aliases
        try {
            return $this->aliases->get($aliasPath);
        } catch (\Throwable) {
            // If alias resolution fails, try treating it as an absolute path
            if (str_starts_with($aliasPath, '/')) {
                return $aliasPath;
            }

            return null;
        }
    }

    /**
     * Format a file size in bytes to a human-readable string.
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $factor < count($units) - 1) {
            $size /= 1024;
            $factor++;
        }

        return round($size, 2) . ' ' . $units[$factor];
    }

    /**
     * Safely retrieve a config group, returning an empty array on failure.
     */
    private function getConfigGroup(string $key): array
    {
        try {
            $data = $this->config->get($key);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Normalize a DI definition for JSON output.
     */
    private function normalizeDefinition(mixed $definition): mixed
    {
        if (is_string($definition)) {
            return $definition;
        }

        if (is_array($definition)) {
            $normalized = [];
            foreach ($definition as $key => $value) {
                $normalized[$key] = $this->normalizeDefinition($value);
            }
            return $normalized;
        }

        if ($definition instanceof Closure) {
            return '[closure]';
        }

        if ($definition instanceof Reference) {
            return ['__reference' => $this->extractReferenceId($definition)];
        }

        if ($definition instanceof DynamicReference) {
            return '[dynamic]';
        }

        if (is_object($definition)) {
            return $definition::class;
        }

        if (is_int($definition) || is_float($definition) || is_bool($definition) || $definition === null) {
            return $definition;
        }

        return (string) $definition;
    }

    /**
     * Extract the ID from a Reference object via reflection on its private $id property.
     */
    private function extractReferenceId(Reference $reference): string
    {
        try {
            $reflection = new ReflectionClass($reference);
            $property = $reflection->getProperty('id');

            return (string) $property->getValue($reference);
        } catch (\Throwable) {
            return '[reference]';
        }
    }
}
