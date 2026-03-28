<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Aliases\Aliases;
use Yiisoft\Config\ConfigInterface;

/**
 * Application Information Tool
 *
 * Provides comprehensive context about the Yii3 application including:
 * - Yii3 and PHP versions
 * - Application environment
 * - Installed packages and extensions
 * - Database connectivity status
 */
final class ApplicationInfoTool extends AbstractTool
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Aliases $aliases,
    ) {
    }

    public function getName(): string
    {
        return 'application_info';
    }

    public function getDescription(): string
    {
        return 'Get comprehensive information about the Yii3 application including version, '
            . 'environment, packages, and extensions';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific info to include: version, environment, packages, php_extensions, database, all',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $include = $arguments['include'] ?? ['version', 'environment', 'packages', 'php_extensions'];

        $result = [];

        if (in_array('version', $include, true) || in_array('all', $include, true)) {
            $result['version'] = $this->getVersionInfo();
        }

        if (in_array('environment', $include, true) || in_array('all', $include, true)) {
            $result['environment'] = $this->getEnvironmentInfo();
        }

        if (in_array('packages', $include, true) || in_array('all', $include, true)) {
            $result['packages'] = $this->getYiiPackages();
        }

        if (in_array('php_extensions', $include, true) || in_array('all', $include, true)) {
            $result['php_extensions'] = $this->getPhpExtensions();
        }

        if (in_array('database', $include, true) || in_array('all', $include, true)) {
            $result['database'] = $this->getDatabaseInfo();
        }

        return $result;
    }

    /**
     * Read yiisoft package versions from composer/installed.json.
     */
    private function getVersionInfo(): array
    {
        $installedPackages = $this->readInstalledJson();
        $yiiVersion = 'unknown';

        // Look for core yii packages to determine the framework version
        $versionPackages = ['yiisoft/yii-http', 'yiisoft/yii-runner', 'yiisoft/yii-console', 'yiisoft/di'];
        foreach ($versionPackages as $pkg) {
            if (isset($installedPackages[$pkg])) {
                $yiiVersion = $installedPackages[$pkg];
                break;
            }
        }

        return [
            'yii_framework' => 'Yii3',
            'yii_core_version' => $yiiVersion,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
        ];
    }

    /**
     * Gather environment information from params and env vars.
     */
    private function getEnvironmentInfo(): array
    {
        $params = [];
        try {
            $params = $this->config->get('params');
        } catch (\Throwable) {
            // Config group may not exist
        }

        $environment = $params['app.environment'] ?? $params['yii.environment']
            ?? getenv('YII_ENV') ?: getenv('APP_ENV') ?: 'unknown';

        $debug = $params['app.debug'] ?? $params['yii.debug']
            ?? getenv('YII_DEBUG') ?: getenv('APP_DEBUG') ?: 'unknown';

        if (is_bool($debug)) {
            $debug = $debug ? 'true' : 'false';
        }

        $paths = [
            'vendor' => $this->resolveAlias('@vendor'),
            'root' => $this->resolveAlias('@root'),
            'runtime' => $this->resolveAlias('@runtime'),
        ];

        return [
            'environment' => $environment,
            'debug' => $debug,
            'paths' => $paths,
        ];
    }

    /**
     * Get all yiisoft/* packages from installed.json.
     */
    private function getYiiPackages(): array
    {
        $installedPackages = $this->readInstalledJson();

        $packages = [];
        foreach ($installedPackages as $name => $version) {
            $packages[] = [
                'name' => $name,
                'version' => $version,
            ];
        }

        usort($packages, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'count' => count($packages),
            'list' => $packages,
        ];
    }

    /**
     * Get loaded PHP extensions.
     */
    private function getPhpExtensions(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions);

        return [
            'count' => count($extensions),
            'list' => $extensions,
        ];
    }

    /**
     * Check database connectivity via the DI container.
     */
    private function getDatabaseInfo(): array
    {
        // Check if a database connection is configured in DI
        $diDefinitions = [];
        try {
            $diDefinitions = $this->config->get('di');
        } catch (\Throwable) {
            // di config group may not exist
        }

        $diWebDefinitions = [];
        try {
            $diWebDefinitions = $this->config->get('di-web');
        } catch (\Throwable) {
            // di-web config group may not exist
        }

        $allDefinitions = array_merge($diDefinitions, $diWebDefinitions);
        $dbInterfaces = [
            'Yiisoft\\Db\\Connection\\ConnectionInterface',
            'Yiisoft\\Db\\ConnectionInterface',
        ];

        $dbConfigured = false;
        foreach ($dbInterfaces as $interface) {
            if (isset($allDefinitions[$interface])) {
                $dbConfigured = true;
                break;
            }
        }

        $result = [
            'configured' => $dbConfigured,
        ];

        // Try to extract DSN from params (common Yii3 pattern)
        $params = [];
        try {
            $params = $this->config->get('params');
        } catch (\Throwable) {
            // params may not exist
        }

        $dsn = $params['yiisoft/db']['dsn'] ?? $params['db.dsn'] ?? null;
        if (is_string($dsn)) {
            // Sanitize DSN — remove password if embedded
            $result['dsn'] = preg_replace('/password=[^;]+/', 'password=***REDACTED***', $dsn) ?? $dsn;
        }

        return $result;
    }

    /**
     * Read and parse vendor/composer/installed.json, filtering for yiisoft/* packages.
     *
     * @return array<string, string> Map of package name => version
     */
    private function readInstalledJson(): array
    {
        $vendorPath = $this->resolveAlias('@vendor');
        if ($vendorPath === null) {
            return [];
        }

        $installedJsonPath = $vendorPath . '/composer/installed.json';
        if (!is_file($installedJsonPath)) {
            return [];
        }

        $content = @file_get_contents($installedJsonPath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        // Composer 2 wraps packages in a "packages" key
        $packages = $data['packages'] ?? $data;
        if (!is_array($packages)) {
            return [];
        }

        $result = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $name = $package['name'] ?? '';
            $version = $package['version'] ?? $package['version_normalized'] ?? 'unknown';

            if (is_string($name) && str_starts_with($name, 'yiisoft/')) {
                $result[$name] = $version;
            }
        }

        return $result;
    }

    /**
     * Safely resolve an alias, returning null on failure.
     */
    private function resolveAlias(string $alias): ?string
    {
        try {
            return $this->aliases->get($alias);
        } catch (\Throwable) {
            return null;
        }
    }
}
