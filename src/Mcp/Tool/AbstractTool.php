<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

/**
 * Base class for MCP tools.
 *
 * Provides shared utility methods (sanitization, class scanning).
 * Intentionally dependency-free — inject services only in concrete tools that need them.
 */
abstract class AbstractTool implements ToolInterface
{
    /**
     * Sensitive key patterns to redact from output.
     */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'key', 'token', 'api_key', 'private_key',
        'auth_key', 'access_token', 'refresh_token', 'client_secret',
        'credential', 'dsn', 'database_url', 'connection_string',
    ];

    /**
     * Sanitize output to remove sensitive data.
     *
     * Recursively walks arrays and redacts values whose keys match sensitive patterns.
     */
    protected function sanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $sanitized[$key] = '***REDACTED***';
                } else {
                    $sanitized[$key] = $this->sanitize($value);
                }
            }
            return $sanitized;
        }

        return $data;
    }

    /**
     * Check if a key matches a sensitive pattern.
     */
    private function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $pattern) {
            if (str_contains($lowerKey, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract fully qualified class name from a PHP file using token parsing.
     */
    protected function getClassNameFromFile(string $file): ?string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $tokens = token_get_all($content);
        $namespace = '';
        $className = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j][0] === T_NAME_QUALIFIED) {
                        $namespace = $tokens[$j][1];
                        break;
                    }
                    if ($tokens[$j][0] === T_STRING) {
                        $namespace .= $tokens[$j][1];
                    } elseif ($tokens[$j][0] === T_NS_SEPARATOR) {
                        $namespace .= '\\';
                    } elseif ($tokens[$j] === ';' || (is_array($tokens[$j]) && $tokens[$j][0] === ord(';'))) {
                        break;
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS && ($i === 0 || $tokens[$i - 1][0] !== T_DOUBLE_COLON)) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        $className = $tokens[$j][1];
                        break;
                    }
                }
            }
        }

        return $namespace && $className ? $namespace . '\\' . $className : null;
    }

    /**
     * Scan directories for PHP classes extending a given parent class.
     *
     * @param array $paths Directories to scan
     * @param string $parentClass Fully qualified parent class name
     * @return array<string> Fully qualified class names
     */
    protected function scanForSubclasses(array $paths, string $parentClass): array
    {
        if (!class_exists($parentClass)) {
            return [];
        }

        $classes = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $className = $this->getClassNameFromFile($file->getPathname());
                if ($className === null) {
                    continue;
                }

                try {
                    if (!class_exists($className)) {
                        continue;
                    }

                    $reflection = new \ReflectionClass($className);
                    if ($reflection->isSubclassOf($parentClass) && !$reflection->isAbstract()) {
                        $classes[] = $className;
                    }
                } catch (\Throwable) {
                    // Skip classes that can't be loaded
                }
            }
        }

        return $classes;
    }
}
