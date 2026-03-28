<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Closure;
use Yiisoft\Config\ConfigInterface;

/**
 * Config Inspector Tool
 *
 * Provides safe access to application configuration including:
 * - DI container definitions (di, di-web, di-console)
 * - Application parameters (params)
 *
 * Automatically sanitizes sensitive data and normalizes non-serializable
 * DI definition values (References, Closures, etc.) into readable strings.
 */
final class ConfigInspectorTool extends AbstractTool
{
    public function __construct(
        private readonly ConfigInterface $config,
    ) {
    }

    public function getName(): string
    {
        return 'config_inspector';
    }

    public function getDescription(): string
    {
        return 'Access application configuration including DI definitions, params, and environment settings '
            . '(with sensitive data redaction)';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'description' => 'Specific config section: params, di, di-web, di-console',
                ],
                'key' => [
                    'type' => 'string',
                    'description' => 'Specific key within the section to retrieve (optional)',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Sections to include: params, di, di-web, di-console, all',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $section = $arguments['section'] ?? null;
        $key = $arguments['key'] ?? null;
        $include = $arguments['include'] ?? null;

        // If a specific section is requested, return just that section
        if ($section !== null) {
            return $this->getSection($section, $key);
        }

        // Determine which sections to include
        $sections = $include ?? ['params', 'di', 'di-web', 'di-console'];
        $includeAll = in_array('all', $sections, true);

        $result = [];

        if ($includeAll || in_array('params', $sections, true)) {
            $result['params'] = $this->getSection('params', $key);
        }

        if ($includeAll || in_array('di', $sections, true)) {
            $result['di'] = $this->getSection('di', $key);
        }

        if ($includeAll || in_array('di-web', $sections, true)) {
            $result['di-web'] = $this->getSection('di-web', $key);
        }

        if ($includeAll || in_array('di-console', $sections, true)) {
            $result['di-console'] = $this->getSection('di-console', $key);
        }

        return $result;
    }

    /**
     * Retrieve a config section, optionally narrowed to a specific key.
     */
    private function getSection(string $section, ?string $key): mixed
    {
        $data = $this->safeConfigGet($section);

        if ($data === null) {
            return ['error' => "Config group '{$section}' not available"];
        }

        // Narrow to a specific key if requested
        if ($key !== null) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return ['error' => "Key '{$key}' not found in '{$section}'"];
            }
            $data = [$key => $data[$key]];
        }

        // For DI sections, normalize definitions to be JSON-serializable
        if (str_starts_with($section, 'di')) {
            $data = $this->normalizeDiDefinitions($data);
        }

        return $this->sanitize($data);
    }

    /**
     * Safely read a config group, returning null on failure.
     */
    private function safeConfigGet(string $group): mixed
    {
        try {
            return $this->config->get($group);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalize DI definitions into JSON-serializable structures.
     *
     * Handles References, DynamicReferences, Closures, and arbitrary objects
     * that the Yii3 DI container accepts but JSON cannot encode.
     *
     * @param mixed $definitions Raw DI config array
     * @return array Normalized array safe for JSON encoding
     */
    private function normalizeDiDefinitions(mixed $definitions): array
    {
        if (!is_array($definitions)) {
            return [];
        }

        $normalized = [];

        foreach ($definitions as $id => $definition) {
            $normalized[$id] = $this->normalizeValue($definition);
        }

        return $normalized;
    }

    /**
     * Recursively normalize a single value from a DI definition.
     */
    private function normalizeValue(mixed $value): mixed
    {
        // Strings pass through (class-string bindings)
        if (is_string($value)) {
            return $value;
        }

        // Scalars and null pass through
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        // Closure
        if ($value instanceof Closure) {
            return '[closure]';
        }

        // Yiisoft Reference — extract the $id property via reflection
        if (is_object($value) && $this->isInstanceOfClass($value, 'Yiisoft\\Definitions\\Reference')) {
            return $this->extractReferenceId($value);
        }

        // Yiisoft DynamicReference
        if (is_object($value) && $this->isInstanceOfClass($value, 'Yiisoft\\Definitions\\DynamicReference')) {
            return '[dynamic reference]';
        }

        // Arrays — may contain nested definitions
        if (is_array($value)) {
            return $this->normalizeArray($value);
        }

        // Any other object — return its class name
        if (is_object($value)) {
            return get_class($value);
        }

        return (string) $value;
    }

    /**
     * Normalize an array value from DI config.
     *
     * Arrays with a 'class' key are treated as definition arrays —
     * we extract the class name and list constructor arguments.
     */
    private function normalizeArray(array $value): array
    {
        // Definition array with 'class' key
        if (isset($value['class']) && is_string($value['class'])) {
            $result = ['class' => $value['class']];

            // Extract constructor arguments if present
            if (isset($value['__construct()'])) {
                $result['constructor_args'] = $this->normalizeValue($value['__construct()']);
            }

            // Include any other string keys as properties
            foreach ($value as $k => $v) {
                if ($k === 'class' || $k === '__construct()') {
                    continue;
                }
                $result[$k] = $this->normalizeValue($v);
            }

            return $result;
        }

        // Generic array — normalize all values recursively
        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[$k] = $this->normalizeValue($v);
        }

        return $normalized;
    }

    /**
     * Extract the id from a Yiisoft\Definitions\Reference via reflection.
     */
    private function extractReferenceId(object $reference): string
    {
        try {
            $reflection = new \ReflectionClass($reference);
            $property = $reflection->getProperty('id');
            $property->setAccessible(true);
            $id = $property->getValue($reference);

            return "Reference -> {$id}";
        } catch (\Throwable) {
            return 'Reference -> [unknown]';
        }
    }

    /**
     * Check if an object is an instance of a class that may or may not be loaded.
     */
    private function isInstanceOfClass(object $obj, string $className): bool
    {
        if (!class_exists($className, false) && !interface_exists($className, false)) {
            // Class not loaded — compare by name in the hierarchy
            return is_a($obj, $className);
        }

        return $obj instanceof $className;
    }
}
