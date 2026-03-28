<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Closure;
use ReflectionClass;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/**
 * Service Inspector Tool
 *
 * Inspects registered DI container services and their definitions.
 * Allows listing all services or inspecting a specific service by
 * its class/interface name.
 */
final class ServiceInspectorTool extends AbstractTool
{
    /** @var array<string, string> Config groups to inspect */
    private const CONFIG_GROUPS = [
        'di' => 'di',
        'di-web' => 'di-web',
        'di-console' => 'di-console',
    ];

    public function __construct(
        private readonly ConfigInterface $config,
    ) {
    }

    public function getName(): string
    {
        return 'service_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect registered DI services and their definitions';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: list, inspect',
                    'enum' => ['list', 'inspect'],
                ],
                'service' => [
                    'type' => 'string',
                    'description' => 'Class or interface name to inspect (required for "inspect" action)',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'] ?? 'list';

        return match ($action) {
            'list' => $this->listServices(),
            'inspect' => $this->inspectService($arguments['service'] ?? ''),
            default => ['error' => "Unknown action: {$action}"],
        };
    }

    /**
     * List all registered services from DI config groups.
     */
    private function listServices(): array
    {
        $services = [];

        foreach (self::CONFIG_GROUPS as $group => $configKey) {
            $definitions = $this->getConfigGroup($configKey);
            if ($definitions === []) {
                continue;
            }

            foreach ($definitions as $id => $definition) {
                $concrete = $this->extractConcreteClass($definition);
                $services[] = [
                    'id' => $id,
                    'concrete' => $concrete,
                    'group' => $group,
                ];
            }
        }

        return [
            'total' => count($services),
            'services' => $services,
        ];
    }

    /**
     * Inspect a specific service definition by ID.
     */
    private function inspectService(string $serviceId): array
    {
        if ($serviceId === '') {
            return ['error' => 'Service ID is required for the "inspect" action'];
        }

        $found = [];

        foreach (self::CONFIG_GROUPS as $group => $configKey) {
            $definitions = $this->getConfigGroup($configKey);

            if (array_key_exists($serviceId, $definitions)) {
                $found[] = [
                    'group' => $group,
                    'definition' => $this->normalizeDefinition($definitions[$serviceId]),
                ];
            }
        }

        if ($found === []) {
            return ['error' => "Service '{$serviceId}' not found in any config group"];
        }

        return [
            'service' => $serviceId,
            'definitions' => $found,
        ];
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
     * Extract the concrete class or label from a DI definition.
     */
    private function extractConcreteClass(mixed $definition): string
    {
        if (is_string($definition)) {
            return $definition;
        }

        if (is_array($definition) && isset($definition['class'])) {
            return (string) $definition['class'];
        }

        if ($definition instanceof Closure) {
            return '[factory closure]';
        }

        if ($definition instanceof Reference) {
            return $this->extractReferenceId($definition);
        }

        if ($definition instanceof DynamicReference) {
            return '[dynamic reference]';
        }

        if (is_object($definition)) {
            return $definition::class;
        }

        if (is_array($definition)) {
            return '[array definition]';
        }

        return '[unknown]';
    }

    /**
     * Normalize a DI definition into a JSON-friendly structure.
     */
    private function normalizeDefinition(mixed $definition): array|string
    {
        if (is_string($definition)) {
            return $definition;
        }

        if (is_array($definition)) {
            $normalized = [];

            if (isset($definition['class'])) {
                $normalized['class'] = (string) $definition['class'];
            }

            if (isset($definition['__construct()'])) {
                $normalized['constructor_args'] = $this->normalizeArgs($definition['__construct()']);
            }

            // Include any other method calls or property assignments
            foreach ($definition as $key => $value) {
                if ($key === 'class' || $key === '__construct()') {
                    continue;
                }
                $normalized[$key] = $this->normalizeValue($value);
            }

            return $normalized === [] ? ['raw' => 'empty array definition'] : $normalized;
        }

        if ($definition instanceof Closure) {
            return '[closure]';
        }

        if ($definition instanceof Reference) {
            return ['type' => 'reference', 'id' => $this->extractReferenceId($definition)];
        }

        if ($definition instanceof DynamicReference) {
            return '[dynamic]';
        }

        if (is_object($definition)) {
            return ['type' => 'object', 'class' => $definition::class];
        }

        return ['type' => gettype($definition), 'value' => $definition];
    }

    /**
     * Normalize constructor arguments for JSON output.
     *
     * @return array<mixed>
     */
    private function normalizeArgs(mixed $args): array
    {
        if (!is_array($args)) {
            return [$this->normalizeValue($args)];
        }

        $normalized = [];
        foreach ($args as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * Normalize a single value for JSON output.
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->normalizeValue($v);
            }
            return $result;
        }

        if ($value instanceof Closure) {
            return '[closure]';
        }

        if ($value instanceof Reference) {
            return ['__reference' => $this->extractReferenceId($value)];
        }

        if ($value instanceof DynamicReference) {
            return '[dynamic]';
        }

        if (is_object($value)) {
            return $value::class;
        }

        return (string) $value;
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
