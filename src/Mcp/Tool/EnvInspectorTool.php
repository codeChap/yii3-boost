<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

/**
 * Environment Inspector Tool
 *
 * Provides environment variables, PHP extensions, PHP configuration,
 * and system information for the running Yii3 application.
 */
final class EnvInspectorTool extends AbstractTool
{
    /**
     * PHP ini keys to report.
     *
     * @var array<string>
     */
    private const PHP_CONFIG_KEYS = [
        'memory_limit',
        'max_execution_time',
        'upload_max_filesize',
        'post_max_size',
        'error_reporting',
        'display_errors',
        'date.timezone',
        'max_input_vars',
        'opcache.enable',
    ];

    public function getName(): string
    {
        return 'env_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect environment variables, PHP extensions, PHP configuration, and system information';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Sections to include: env_vars, extensions, php_config, system, all',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'Filter environment variable keys by prefix (e.g., "DB", "APP")',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $include = $arguments['include'] ?? ['env_vars', 'extensions', 'php_config'];
        $filter = $arguments['filter'] ?? null;

        $result = [];

        if (in_array('env_vars', $include) || in_array('all', $include)) {
            $result['env_vars'] = $this->getEnvVars($filter);
        }

        if (in_array('extensions', $include) || in_array('all', $include)) {
            $result['extensions'] = $this->getExtensions();
        }

        if (in_array('php_config', $include) || in_array('all', $include)) {
            $result['php_config'] = $this->getPhpConfig();
        }

        if (in_array('system', $include) || in_array('all', $include)) {
            $result['system'] = $this->getSystemInfo();
        }

        return $result;
    }

    /**
     * Get environment variables, optionally filtered by prefix.
     *
     * @param string|null $filter Prefix filter
     * @return array Sanitized environment variables
     */
    private function getEnvVars(?string $filter): array
    {
        $envVars = getenv();

        if (!is_array($envVars)) {
            return [];
        }

        if ($filter !== null && $filter !== '') {
            $filtered = [];
            $upperFilter = strtoupper($filter);
            foreach ($envVars as $key => $value) {
                if (stripos($key, $upperFilter) === 0) {
                    $filtered[$key] = $value;
                }
            }
            $envVars = $filtered;
        }

        ksort($envVars);

        return $this->sanitize($envVars);
    }

    /**
     * Get loaded PHP extensions.
     *
     * @return array Extensions info
     */
    private function getExtensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'count' => count($extensions),
            'list' => $extensions,
        ];
    }

    /**
     * Get key PHP configuration values.
     *
     * @return array PHP ini values
     */
    private function getPhpConfig(): array
    {
        $config = [];

        foreach (self::PHP_CONFIG_KEYS as $key) {
            $value = ini_get($key);
            $config[$key] = $value !== false ? $value : null;
        }

        return $config;
    }

    /**
     * Get system information.
     *
     * @return array System details
     */
    private function getSystemInfo(): array
    {
        return [
            'os' => PHP_OS,
            'os_detail' => php_uname(),
            'architecture' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
            'cwd' => getcwd() ?: 'unknown',
        ];
    }
}
