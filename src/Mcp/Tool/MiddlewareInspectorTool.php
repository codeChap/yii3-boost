<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Closure;
use ReflectionClass;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Definitions\DynamicReference;
use Yiisoft\Definitions\Reference;

/**
 * Middleware Inspector Tool
 *
 * Inspects the PSR-15 middleware pipeline and per-route middleware
 * configured in the Yii3 application.
 */
final class MiddlewareInspectorTool extends AbstractTool
{
    public function __construct(
        private readonly ConfigInterface $config,
    ) {
    }

    public function getName(): string
    {
        return 'middleware_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect the PSR-15 middleware pipeline and per-route middleware';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: list (application-level middleware), routes (per-route middleware)',
                    'enum' => ['list', 'routes'],
                ],
                'route' => [
                    'type' => 'string',
                    'description' => 'Route name to filter by (optional, used with "routes" action)',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'] ?? 'list';

        return match ($action) {
            'list' => $this->listApplicationMiddleware(),
            'routes' => $this->listRouteMiddleware($arguments['route'] ?? null),
            default => ['error' => "Unknown action: {$action}"],
        };
    }

    /**
     * List application-level middleware from the DI web config.
     */
    private function listApplicationMiddleware(): array
    {
        $diWeb = $this->getConfigGroup('di-web');
        $middleware = [];

        foreach ($diWeb as $serviceId => $definition) {
            // Look for Application::class or MiddlewareDispatcher::class keys
            if (
                !str_contains($serviceId, 'Application') &&
                !str_contains($serviceId, 'MiddlewareDispatcher')
            ) {
                continue;
            }

            $extracted = $this->extractMiddlewareFromDefinition($definition);
            if ($extracted !== []) {
                $middleware[] = [
                    'service' => $serviceId,
                    'middlewares' => $extracted,
                ];
            }
        }

        // Also scan for any key with 'withMiddlewares()' in array definitions
        foreach ($diWeb as $serviceId => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            // Skip already-processed keys
            if (
                str_contains($serviceId, 'Application') ||
                str_contains($serviceId, 'MiddlewareDispatcher')
            ) {
                continue;
            }

            if (isset($definition['withMiddlewares()'])) {
                $middlewareList = $definition['withMiddlewares()'];
                $extracted = $this->normalizeMiddlewareList(
                    is_array($middlewareList) ? $middlewareList : [$middlewareList],
                );
                if ($extracted !== []) {
                    $middleware[] = [
                        'service' => $serviceId,
                        'middlewares' => $extracted,
                    ];
                }
            }
        }

        return [
            'total' => count($middleware),
            'application_middleware' => $middleware,
        ];
    }

    /**
     * List per-route middleware from route definitions.
     */
    private function listRouteMiddleware(?string $routeFilter): array
    {
        $routes = $this->getConfigGroup('routes');
        $results = [];

        $this->walkRoutes($routes, $results, $routeFilter);

        return [
            'total' => count($results),
            'route_middleware' => $results,
        ];
    }

    /**
     * Recursively walk route definitions to extract middleware.
     *
     * @param mixed $routes Route config (array of Route/Group objects or arrays)
     * @param array<array> $results Collected results
     * @param string|null $routeFilter Optional route name filter
     */
    private function walkRoutes(mixed $routes, array &$results, ?string $routeFilter): void
    {
        if (!is_array($routes) && !is_iterable($routes)) {
            return;
        }

        foreach ($routes as $route) {
            if (!is_object($route)) {
                continue;
            }

            $routeName = null;
            $routePattern = null;
            $middlewareList = [];

            // Try extracting data via getData() method (Yii3 Route/Group)
            if (method_exists($route, 'getData')) {
                $data = $route->getData('enabledMiddlewares');
                $hasMiddlewares = $route->getData('hasMiddlewares');

                if ($hasMiddlewares || !empty($data)) {
                    $middlewareList = is_array($data) ? $data : [];
                }

                $routeName = $route->getData('name');
                $routePattern = $route->getData('pattern');
            }

            // Fallback: try common method names
            if ($routeName === null && method_exists($route, 'getName')) {
                $routeName = $route->getName();
            }
            if ($routePattern === null && method_exists($route, 'getPattern')) {
                $routePattern = $route->getPattern();
            }

            $identifier = $routeName ?? $routePattern ?? $route::class;

            // Apply filter if specified
            if ($routeFilter !== null && $routeFilter !== '') {
                if (
                    ($routeName !== null && !str_contains($routeName, $routeFilter)) &&
                    ($routePattern !== null && !str_contains($routePattern, $routeFilter))
                ) {
                    // Still recurse into groups
                    $this->walkGroupRoutes($route, $results, $routeFilter);
                    continue;
                }
            }

            if ($middlewareList !== []) {
                $results[] = [
                    'route' => $identifier,
                    'pattern' => $routePattern,
                    'middlewares' => $this->normalizeMiddlewareList($middlewareList),
                ];
            }

            // Recurse into groups
            $this->walkGroupRoutes($route, $results, $routeFilter);
        }
    }

    /**
     * If a route is a Group, recurse into its child routes.
     */
    private function walkGroupRoutes(object $route, array &$results, ?string $routeFilter): void
    {
        // Yiisoft\Router\Group stores items
        if (method_exists($route, 'getData')) {
            $items = $route->getData('items');
            if (is_array($items) || is_iterable($items)) {
                $this->walkRoutes($items, $results, $routeFilter);
            }
        }
    }

    /**
     * Extract middleware from a DI definition.
     *
     * @return array<string>
     */
    private function extractMiddlewareFromDefinition(mixed $definition): array
    {
        if (!is_array($definition)) {
            return [];
        }

        // Direct 'withMiddlewares()' key
        if (isset($definition['withMiddlewares()'])) {
            $middlewareList = $definition['withMiddlewares()'];
            // withMiddlewares() expects [[middleware1, middleware2, ...]]
            if (is_array($middlewareList)) {
                return $this->normalizeMiddlewareList($middlewareList);
            }
        }

        // Check nested array — the definition might have the class + method calls pattern
        // e.g., ['class' => X::class, 'withMiddlewares()' => [[...]]]
        foreach ($definition as $key => $value) {
            if (is_string($key) && str_contains($key, 'withMiddlewares')) {
                if (is_array($value)) {
                    return $this->normalizeMiddlewareList($value);
                }
            }
        }

        return [];
    }

    /**
     * Normalize a middleware list (potentially nested) into string labels.
     *
     * @param array<mixed> $middlewares
     * @return array<string>
     */
    private function normalizeMiddlewareList(array $middlewares): array
    {
        $normalized = [];

        foreach ($middlewares as $middleware) {
            if (is_string($middleware)) {
                $normalized[] = $middleware;
            } elseif (is_array($middleware)) {
                // withMiddlewares() wraps the list in an extra array: [[M1, M2, ...]]
                foreach ($middleware as $inner) {
                    $normalized[] = $this->normalizeMiddlewareValue($inner);
                }
            } elseif ($middleware instanceof Closure) {
                $normalized[] = '[closure]';
            } elseif ($middleware instanceof Reference) {
                $normalized[] = $this->extractReferenceId($middleware);
            } elseif ($middleware instanceof DynamicReference) {
                $normalized[] = '[dynamic]';
            } elseif (is_object($middleware)) {
                $normalized[] = $middleware::class;
            }
        }

        return $normalized;
    }

    /**
     * Normalize a single middleware value to a string label.
     */
    private function normalizeMiddlewareValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Closure) {
            return '[closure]';
        }

        if ($value instanceof Reference) {
            return $this->extractReferenceId($value);
        }

        if ($value instanceof DynamicReference) {
            return '[dynamic]';
        }

        if (is_object($value)) {
            return $value::class;
        }

        if (is_array($value)) {
            // Could be a callable array [Controller::class, 'method']
            return implode('::', array_map(
                static fn(mixed $v): string => is_string($v) ? $v : gettype($v),
                $value,
            ));
        }

        return '[unknown]';
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
