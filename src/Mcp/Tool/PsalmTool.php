<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Aliases\Aliases;

/**
 * Psalm Security & Analysis Tool
 *
 * Runs Psalm static analysis with optional taint analysis on the host
 * Yii3 application. Supports scanning specific files/directories,
 * filtering by error level, and running taint analysis to detect
 * security vulnerabilities like SQL injection, XSS, and path traversal.
 */
final class PsalmTool extends AbstractTool
{
    public function __construct(
        private readonly Aliases $aliases,
    ) {
    }

    public function getName(): string
    {
        return 'psalm';
    }

    public function getDescription(): string
    {
        return 'Run Psalm static analysis or taint security analysis on the application codebase';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['analyze', 'taint', 'info'],
                    'description' => 'Analysis mode: "analyze" for general static analysis, "taint" for security taint analysis, "info" for Psalm version and config status (default: analyze)',
                ],
                'paths' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific files or directories to analyze (relative to project root). If omitted, uses psalm.xml config.',
                ],
                'level' => [
                    'type' => 'integer',
                    'description' => 'Error level 1-8 (1 = strictest, 8 = most lenient). Overrides psalm.xml setting.',
                ],
                'show_info' => [
                    'type' => 'boolean',
                    'description' => 'Include informational issues (not just errors). Default: false.',
                ],
                'max_issues' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of issues to return (default: 50)',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $mode = $arguments['mode'] ?? 'analyze';

        if ($mode === 'info') {
            return $this->getPsalmInfo();
        }

        $rootPath = $this->getRootPath();
        $psalmBin = $this->findPsalmBinary($rootPath);

        if ($psalmBin === null) {
            return [
                'error' => 'Psalm not found. Install it: composer require --dev vimeo/psalm',
                'searched' => [
                    $rootPath . '/vendor/bin/psalm',
                ],
            ];
        }

        $psalmConfig = $rootPath . '/psalm.xml';
        if (!file_exists($psalmConfig)) {
            $psalmConfig = $rootPath . '/psalm.xml.dist';
        }
        if (!file_exists($psalmConfig)) {
            return [
                'error' => 'No psalm.xml or psalm.xml.dist found in project root.',
                'hint' => 'Run: vendor/bin/psalm --init to create one.',
            ];
        }

        return match ($mode) {
            'taint' => $this->runTaintAnalysis($psalmBin, $rootPath, $psalmConfig, $arguments),
            default => $this->runAnalysis($psalmBin, $rootPath, $psalmConfig, $arguments),
        };
    }

    private function runAnalysis(string $psalmBin, string $rootPath, string $configPath, array $arguments): array
    {
        $cmd = $this->buildCommand($psalmBin, $configPath, $arguments);

        return $this->executeCommand($cmd, $rootPath, 'analyze', $arguments);
    }

    private function runTaintAnalysis(string $psalmBin, string $rootPath, string $configPath, array $arguments): array
    {
        $cmd = $this->buildCommand($psalmBin, $configPath, $arguments);
        $cmd[] = '--taint-analysis';

        return $this->executeCommand($cmd, $rootPath, 'taint', $arguments);
    }

    /**
     * Build the Psalm command array.
     */
    private function buildCommand(string $psalmBin, string $configPath, array $arguments): array
    {
        $cmd = ['php', $psalmBin, '--output-format=json', '--no-progress', '--config=' . $configPath];

        if (isset($arguments['level'])) {
            $level = max(1, min(8, (int) $arguments['level']));
            $cmd[] = '--error-level=' . $level;
        }

        if (!empty($arguments['show_info'])) {
            $cmd[] = '--show-info=true';
        }

        if (!empty($arguments['paths'])) {
            foreach ($arguments['paths'] as $path) {
                // Prevent path traversal outside project
                $normalized = str_replace('..', '', $path);
                $cmd[] = $normalized;
            }
        }

        return $cmd;
    }

    /**
     * Execute a Psalm command and parse results.
     */
    private function executeCommand(array $cmd, string $rootPath, string $mode, array $arguments): array
    {
        $maxIssues = $arguments['max_issues'] ?? 50;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $rootPath);

        if (!is_resource($process)) {
            return ['error' => 'Failed to start Psalm process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $issues = [];
        if ($stdout !== false && $stdout !== '') {
            $decoded = json_decode($stdout, true);
            if (is_array($decoded)) {
                $issues = $decoded;
            }
        }

        // Group issues by severity
        $grouped = [
            'errors' => [],
            'warnings' => [],
            'info' => [],
        ];

        $totalCount = count($issues);

        foreach (array_slice($issues, 0, (int) $maxIssues) as $issue) {
            $entry = [
                'type' => $issue['type'] ?? 'Unknown',
                'message' => $issue['message'] ?? '',
                'file' => $this->relativePath($issue['file_path'] ?? '', $rootPath),
                'line' => $issue['line_from'] ?? null,
                'snippet' => $issue['snippet'] ?? null,
            ];

            // Add taint trace if present
            if (!empty($issue['taint_trace'])) {
                $entry['taint_trace'] = $this->formatTaintTrace($issue['taint_trace'], $rootPath);
            }

            $severity = $issue['severity'] ?? 'error';
            match ($severity) {
                'error' => $grouped['errors'][] = $entry,
                'info' => $grouped['info'][] = $entry,
                default => $grouped['warnings'][] = $entry,
            };
        }

        // Remove empty groups
        $grouped = array_filter($grouped);

        $result = [
            'mode' => $mode,
            'exit_code' => $exitCode,
            'total_issues' => $totalCount,
            'showing' => min($totalCount, (int) $maxIssues),
            'summary' => [
                'errors' => count($grouped['errors'] ?? []),
                'warnings' => count($grouped['warnings'] ?? []),
                'info' => count($grouped['info'] ?? []),
            ],
        ];

        if (!empty($grouped)) {
            $result['issues'] = $grouped;
        }

        if ($exitCode === 0 && $totalCount === 0) {
            $result['status'] = $mode === 'taint'
                ? 'No taint vulnerabilities found'
                : 'No issues found';
        }

        // Include stderr hints if Psalm itself errored
        if ($exitCode > 1 && $stderr !== false && $stderr !== '') {
            $result['psalm_error'] = trim($stderr);
        }

        return $result;
    }

    private function getPsalmInfo(): array
    {
        $rootPath = $this->getRootPath();
        $psalmBin = $this->findPsalmBinary($rootPath);

        $info = [
            'installed' => $psalmBin !== null,
            'psalm_xml_exists' => file_exists($rootPath . '/psalm.xml') || file_exists($rootPath . '/psalm.xml.dist'),
        ];

        if ($psalmBin !== null) {
            $version = trim((string) shell_exec('php ' . escapeshellarg($psalmBin) . ' --version 2>/dev/null'));
            $info['version'] = $version ?: 'unknown';
        }

        return $info;
    }

    private function findPsalmBinary(string $rootPath): ?string
    {
        $path = $rootPath . '/vendor/bin/psalm';
        return file_exists($path) ? $path : null;
    }

    private function getRootPath(): string
    {
        return rtrim($this->aliases->get('@root'), '/');
    }

    private function relativePath(string $path, string $rootPath): string
    {
        if (str_starts_with($path, $rootPath)) {
            return ltrim(substr($path, strlen($rootPath)), '/');
        }
        return $path;
    }

    /**
     * Format taint trace for readable output.
     */
    private function formatTaintTrace(array $trace, string $rootPath): array
    {
        $formatted = [];
        foreach ($trace as $step) {
            $formatted[] = [
                'file' => $this->relativePath($step['file_path'] ?? '', $rootPath),
                'line' => $step['line_from'] ?? null,
                'snippet' => $step['snippet'] ?? null,
            ];
        }
        return $formatted;
    }
}
