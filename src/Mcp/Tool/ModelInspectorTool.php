<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * Model Inspector Tool
 *
 * Provides Active Record model analysis including:
 * - Attributes with types from table schema
 * - Relations (hasOne/hasMany) discovered via get*Query() methods
 * - Fields for API serialization
 */
final class ModelInspectorTool extends AbstractTool
{
    private const ACTIVE_RECORD_CLASS = 'Yiisoft\ActiveRecord\ActiveRecord';
    private const ACTIVE_QUERY_INTERFACE = 'Yiisoft\ActiveRecord\ActiveQueryInterface';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ConfigInterface $config,
    ) {
    }

    public function getName(): string
    {
        return 'model_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect Active Record models including attributes, relations, and fields';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'model' => [
                    'type' => 'string',
                    'description' => 'Model class name or short name (e.g., "User" or "App\\Model\\User"). Omit to list all models.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: attributes, relations, fields, all. Defaults to all.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $modelName = $arguments['model'] ?? null;
        $include = $arguments['include'] ?? [];

        $includeAll = $include === [] || in_array('all', $include, true);

        try {
            $params = $this->config->get('params');
            $modelPaths = $params['codechap/yii3-boost']['modelPaths'] ?? [];
            $parentClass = $params['codechap/yii3-boost']['modelParentClass'] ?? self::ACTIVE_RECORD_CLASS;

            if (!class_exists($parentClass)) {
                return [
                    'error' => "ActiveRecord parent class '{$parentClass}' not found. Is yiisoft/active-record installed?",
                ];
            }

            if ($modelName === null) {
                return $this->listModels($modelPaths, $parentClass);
            }

            return $this->inspectModel($modelName, $modelPaths, $parentClass, $include, $includeAll);
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ];
        }
    }

    /**
     * List all discoverable model classes.
     *
     * @return array{models: list<array{class: string, short_name: string}>, total: int, paths_scanned: list<string>}
     */
    private function listModels(array $modelPaths, string $parentClass): array
    {
        $classes = $this->scanForSubclasses($modelPaths, $parentClass);
        sort($classes);

        $models = [];
        foreach ($classes as $className) {
            $parts = explode('\\', $className);
            $models[] = [
                'class' => $className,
                'short_name' => end($parts),
            ];
        }

        return [
            'models' => $models,
            'total' => count($models),
            'paths_scanned' => $modelPaths,
        ];
    }

    /**
     * Inspect a single model in detail.
     */
    private function inspectModel(
        string $modelName,
        array $modelPaths,
        string $parentClass,
        array $include,
        bool $includeAll,
    ): array {
        $className = $this->resolveModelClass($modelName, $modelPaths, $parentClass);

        if ($className === null) {
            $available = $this->scanForSubclasses($modelPaths, $parentClass);
            sort($available);

            return [
                'error' => "Model '{$modelName}' not found",
                'available_models' => $available,
            ];
        }

        /** @var \Yiisoft\ActiveRecord\ActiveRecord $instance */
        $instance = new $className($this->db);

        $result = [
            'class' => $className,
            'table' => $instance->tableName(),
            'primary_key' => $instance->primaryKey(),
        ];

        if ($includeAll || in_array('attributes', $include, true)) {
            $result['attributes'] = $this->extractAttributes($instance);
        }

        if ($includeAll || in_array('relations', $include, true)) {
            $result['relations'] = $this->extractRelations($className);
        }

        if ($includeAll || in_array('fields', $include, true)) {
            $result['fields'] = $this->extractFields($instance);
        }

        return $result;
    }

    /**
     * Resolve a model name (short or fully qualified) to its class name.
     */
    private function resolveModelClass(string $modelName, array $modelPaths, string $parentClass): ?string
    {
        // If already a fully qualified class name and valid
        if (class_exists($modelName) && is_subclass_of($modelName, $parentClass)) {
            return $modelName;
        }

        // Try to find by short name among discovered classes
        $classes = $this->scanForSubclasses($modelPaths, $parentClass);

        foreach ($classes as $className) {
            $parts = explode('\\', $className);
            $shortName = end($parts);

            if (strcasecmp($shortName, $modelName) === 0) {
                return $className;
            }
        }

        // Try common namespace patterns with the short name
        foreach ($classes as $className) {
            if (str_ends_with($className, '\\' . $modelName)) {
                return $className;
            }
        }

        return null;
    }

    /**
     * Extract attributes with type information from table schema.
     *
     * @return list<array{name: string, type: string|null, db_type: string|null, nullable: bool, default: mixed, primary_key: bool, auto_increment: bool}>
     */
    private function extractAttributes(object $instance): array
    {
        $propertyNames = $instance->propertyNames();
        $attributes = [];

        // Try to get column metadata from the table schema
        $columnMap = [];
        try {
            $tableSchema = $instance->tableSchema();
            if ($tableSchema !== null) {
                foreach ($tableSchema->getColumns() as $name => $column) {
                    $columnMap[$name] = $column;
                }
            }
        } catch (\Throwable) {
            // Table schema may not be available
        }

        foreach ($propertyNames as $name) {
            $column = $columnMap[$name] ?? null;

            $attr = [
                'name' => $name,
                'type' => $column?->getType(),
                'db_type' => $column?->getDbType(),
                'nullable' => $column !== null ? ($column->isNotNull() !== true) : true,
                'default' => $column?->getDefaultValue(),
                'primary_key' => $column?->isPrimaryKey() ?? false,
                'auto_increment' => $column?->isAutoIncrement() ?? false,
            ];

            // Include current value if set
            try {
                $value = $instance->get($name);
                if ($value !== null) {
                    $attr['current_value'] = $value;
                }
            } catch (\Throwable) {
                // Property may not be readable without data
            }

            $attributes[] = $attr;
        }

        return $attributes;
    }

    /**
     * Discover relations by reflecting on get*Query() methods.
     *
     * In Yii3, relation methods follow the pattern getXxxQuery() and return ActiveQueryInterface.
     *
     * @return list<array{name: string, method: string, return_type: string|null}>
     */
    private function extractRelations(string $className): array
    {
        $relations = [];

        try {
            $reflection = new ReflectionClass($className);
        } catch (\ReflectionException) {
            return [];
        }

        $activeQueryInterface = self::ACTIVE_QUERY_INTERFACE;
        $hasActiveQueryInterface = class_exists($activeQueryInterface) || interface_exists($activeQueryInterface);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            // Match get*Query() pattern
            if (
                !str_starts_with($methodName, 'get')
                || !str_ends_with($methodName, 'Query')
                || $methodName === 'getQuery'
            ) {
                continue;
            }

            // Skip methods inherited from the base ActiveRecord class
            if (
                $method->getDeclaringClass()->getName() !== $className
                && !str_starts_with($method->getDeclaringClass()->getName(), $reflection->getNamespaceName())
            ) {
                continue;
            }

            // Check return type
            $returnType = $method->getReturnType();
            $returnTypeName = null;

            if ($returnType instanceof ReflectionNamedType) {
                $returnTypeName = $returnType->getName();

                // Verify it implements/is ActiveQueryInterface
                if ($hasActiveQueryInterface && !$this->isActiveQueryType($returnTypeName, $activeQueryInterface)) {
                    continue;
                }
            }

            // Derive relation name: getOrdersQuery -> orders
            $relationName = substr($methodName, 3, -5); // Remove 'get' prefix and 'Query' suffix
            $relationName = lcfirst($relationName);

            $relation = [
                'name' => $relationName,
                'method' => $methodName,
                'return_type' => $returnTypeName,
            ];

            // Try to get parameter count to infer if it's a simple relation
            if ($method->getNumberOfRequiredParameters() === 0) {
                $relation['callable_without_args'] = true;
            }

            $relations[] = $relation;
        }

        // Sort by name
        usort($relations, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $relations;
    }

    /**
     * Check whether a type name is or implements the ActiveQueryInterface.
     */
    private function isActiveQueryType(string $typeName, string $activeQueryInterface): bool
    {
        if ($typeName === $activeQueryInterface) {
            return true;
        }

        try {
            if (class_exists($typeName)) {
                $ref = new ReflectionClass($typeName);
                return $ref->implementsInterface($activeQueryInterface);
            }
            if (interface_exists($typeName)) {
                $ref = new ReflectionClass($typeName);
                return $typeName === $activeQueryInterface || $ref->isSubclassOf($activeQueryInterface);
            }
        } catch (\Throwable) {
            // Can't reflect, be permissive
        }

        return false;
    }

    /**
     * Extract fields for API serialization.
     *
     * In Yii3 ActiveRecord, fields() returns property names by default.
     * extraFields() returns additional fields available on request.
     *
     * @return array{fields: list<string>, extra_fields: list<string>}
     */
    private function extractFields(object $instance): array
    {
        $fields = [];
        $extraFields = [];

        // fields() returns an array of field names or name => definition pairs
        if (method_exists($instance, 'fields')) {
            try {
                $rawFields = $instance->fields();
                foreach ($rawFields as $key => $value) {
                    // If numeric key, value is the field name; otherwise key is the field name
                    $fields[] = is_int($key) ? $value : $key;
                }
            } catch (\Throwable) {
                // fields() might fail without proper initialization
            }
        }

        // extraFields() returns additional fields available on expand
        if (method_exists($instance, 'extraFields')) {
            try {
                $rawExtraFields = $instance->extraFields();
                foreach ($rawExtraFields as $key => $value) {
                    $extraFields[] = is_int($key) ? $value : $key;
                }
            } catch (\Throwable) {
                // extraFields() might fail without proper initialization
            }
        }

        return [
            'fields' => $fields,
            'extra_fields' => $extraFields,
        ];
    }
}
