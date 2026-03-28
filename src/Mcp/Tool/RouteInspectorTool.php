<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Config\ConfigInterface;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/**
 * Route Inspector Tool
 *
 * Provides complete route mapping including:
 * - Routes from the Yii3 router configuration
 * - Route patterns, methods, and handler mappings
 * - Group/prefix information
 * - Filtering by name pattern or HTTP method
 *
 * Recursively flattens Group objects into individual routes with full prefix paths.
 */
final class RouteInspectorTool extends AbstractTool
{
    public function __construct(
        private readonly ConfigInterface $config,
    ) {
    }

    public function getName(): string
    {
        return 'route_inspector';
    }

    public function getDescription(): string
    {
        return 'Inspect application routes and URL rules including route patterns, methods, and handlers';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name_filter' => [
                    'type' => 'string',
                    'description' => 'Filter routes by name pattern (substring match, case-insensitive)',
                ],
                'method_filter' => [
                    'type' => 'string',
                    'description' => 'Filter routes by HTTP method (e.g. GET, POST)',
                ],
                'group_prefix' => [
                    'type' => 'string',
                    'description' => 'Filter routes belonging to a specific group prefix (e.g. /api)',
                ],
                'include_patterns' => [
                    'type' => 'boolean',
                    'description' => 'Include the full string representation of each route',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $nameFilter = $arguments['name_filter'] ?? null;
        $methodFilter = $arguments['method_filter'] ?? null;
        $groupPrefix = $arguments['group_prefix'] ?? null;
        $includePatterns = $arguments['include_patterns'] ?? false;

        $routes = $this->loadRoutes();

        if ($routes === null) {
            return ['error' => 'Routes configuration not available. Ensure the "routes" config group exists.'];
        }

        // Recursively flatten all Group and Route objects
        $flatRoutes = $this->flattenRoutes($routes, '', '');

        // Apply filters
        $filtered = $this->applyFilters($flatRoutes, $nameFilter, $methodFilter, $groupPrefix);

        // Build output
        $output = [];
        foreach ($filtered as $route) {
            $entry = [
                'name' => $route['name'],
                'pattern' => $route['pattern'],
                'methods' => $route['methods'],
            ];

            if (!empty($route['hosts'])) {
                $entry['hosts'] = $route['hosts'];
            }

            $entry['has_middleware'] = $route['has_middleware'];

            if ($includePatterns && $route['string_repr'] !== null) {
                $entry['definition'] = $route['string_repr'];
            }

            $output[] = $entry;
        }

        return [
            'count' => count($output),
            'routes' => $output,
        ];
    }

    /**
     * Load routes from config.
     *
     * @return array|null Raw route/group objects, or null if unavailable
     */
    private function loadRoutes(): ?array
    {
        try {
            $routes = $this->config->get('routes');
            return is_array($routes) ? $routes : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively flatten Route and Group objects into a flat array of route info.
     *
     * @param array  $items       Array of Route and Group objects
     * @param string $prefixPath  Accumulated path prefix from parent groups
     * @param string $prefixName  Accumulated name prefix from parent groups
     * @return array<array{name: string|null, pattern: string, methods: array, hosts: array, has_middleware: bool, string_repr: string|null}>
     */
    private function flattenRoutes(array $items, string $prefixPath, string $prefixName): array
    {
        $result = [];

        foreach ($items as $item) {
            if ($item instanceof Route) {
                $result[] = $this->extractRouteInfo($item, $prefixPath, $prefixName);
            } elseif ($item instanceof Group) {
                $groupPrefix = $item->getData('prefix') ?? '';
                $groupNamePrefix = $item->getData('namePrefix') ?? '';
                $groupItems = $item->getData('routes') ?? [];
                $groupHosts = $item->getData('hosts') ?? [];

                $fullPathPrefix = rtrim($prefixPath, '/') . '/' . ltrim($groupPrefix, '/');
                // Normalize double slashes (except a leading /)
                $fullPathPrefix = '/' . ltrim(preg_replace('#/+#', '/', $fullPathPrefix) ?? '', '/');

                $fullNamePrefix = $prefixName . $groupNamePrefix;

                $nested = $this->flattenRoutes(
                    is_array($groupItems) ? $groupItems : [],
                    $fullPathPrefix,
                    $fullNamePrefix,
                );

                // Merge group-level hosts into child routes that have no hosts
                if (!empty($groupHosts) && is_array($groupHosts)) {
                    foreach ($nested as &$route) {
                        if (empty($route['hosts'])) {
                            $route['hosts'] = $groupHosts;
                        }
                    }
                    unset($route);
                }

                array_push($result, ...$nested);
            }
        }

        return $result;
    }

    /**
     * Extract normalized info from a single Route object.
     */
    private function extractRouteInfo(Route $route, string $prefixPath, string $prefixName): array
    {
        $name = $route->getData('name');
        $pattern = $route->getData('pattern') ?? '';
        $methods = $route->getData('methods') ?? [];
        $hosts = $route->getData('hosts') ?? [];
        $hasMiddleware = (bool) $route->getData('hasMiddlewares');

        // Build full pattern with group prefix
        $fullPattern = $pattern;
        if ($prefixPath !== '' && $prefixPath !== '/') {
            $fullPattern = rtrim($prefixPath, '/') . '/' . ltrim($pattern, '/');
        }
        // Normalize double slashes
        $fullPattern = '/' . ltrim(preg_replace('#/+#', '/', $fullPattern) ?? '', '/');

        // Build full name with group name prefix
        $fullName = $name;
        if ($fullName !== null && $prefixName !== '') {
            $fullName = $prefixName . $fullName;
        }

        // Get string representation
        $stringRepr = null;
        try {
            $stringRepr = (string) $route;
        } catch (\Throwable) {
            // Some routes may not be stringable
        }

        return [
            'name' => $fullName,
            'pattern' => $fullPattern,
            'methods' => is_array($methods) ? $methods : [],
            'hosts' => is_array($hosts) ? $hosts : [],
            'has_middleware' => $hasMiddleware,
            'string_repr' => $stringRepr,
        ];
    }

    /**
     * Apply optional filters to the flat route list.
     *
     * @param array       $routes       Flat route list
     * @param string|null $nameFilter   Name substring filter (case-insensitive)
     * @param string|null $methodFilter HTTP method filter
     * @param string|null $groupPrefix  Group prefix filter
     * @return array Filtered routes
     */
    private function applyFilters(
        array $routes,
        ?string $nameFilter,
        ?string $methodFilter,
        ?string $groupPrefix,
    ): array {
        return array_values(array_filter($routes, static function (array $route) use ($nameFilter, $methodFilter, $groupPrefix): bool {
            // Filter by name pattern
            if ($nameFilter !== null && $nameFilter !== '') {
                $name = $route['name'] ?? '';
                if (!str_contains(strtolower($name), strtolower($nameFilter))) {
                    return false;
                }
            }

            // Filter by HTTP method
            if ($methodFilter !== null && $methodFilter !== '') {
                $methods = $route['methods'];
                if (!empty($methods) && !in_array(strtoupper($methodFilter), $methods, true)) {
                    return false;
                }
            }

            // Filter by group prefix (pattern starts with the given prefix)
            if ($groupPrefix !== null && $groupPrefix !== '') {
                $normalizedPrefix = '/' . ltrim($groupPrefix, '/');
                if (!str_starts_with($route['pattern'], $normalizedPrefix)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
