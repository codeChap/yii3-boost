<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp;

use codechap\yii3boost\Mcp\Tool\ApplicationInfoTool;
use codechap\yii3boost\Mcp\Tool\ConfigInspectorTool;
use codechap\yii3boost\Mcp\Tool\ConsoleCommandInspectorTool;
use codechap\yii3boost\Mcp\Tool\DatabaseQueryTool;
use codechap\yii3boost\Mcp\Tool\DatabaseSchemaTool;
use codechap\yii3boost\Mcp\Tool\DevServerTool;
use codechap\yii3boost\Mcp\Tool\EnvInspectorTool;
use codechap\yii3boost\Mcp\Tool\LogInspectorTool;
use codechap\yii3boost\Mcp\Tool\MiddlewareInspectorTool;
use codechap\yii3boost\Mcp\Tool\MigrationInspectorTool;
use codechap\yii3boost\Mcp\Tool\ModelInspectorTool;
use codechap\yii3boost\Mcp\Tool\PerformanceProfilerTool;
use codechap\yii3boost\Mcp\Tool\RouteInspectorTool;
use codechap\yii3boost\Mcp\Tool\SemanticSearchTool;
use codechap\yii3boost\Mcp\Tool\ServiceInspectorTool;
use codechap\yii3boost\Mcp\Tool\TinkerTool;
use codechap\yii3boost\Mcp\Tool\ToolInterface;
use codechap\yii3boost\Mcp\Transport\TransportInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MCP Server for Yii3 Applications.
 *
 * Implements JSON-RPC 2.0 over STDIO for the Model Context Protocol.
 * Tools are lazily resolved from the DI container on demand.
 */
final class Server
{
    public const VERSION = '1.0.0';

    /**
     * Built-in tool name → class mapping.
     */
    private const TOOL_MAP = [
        'application_info' => ApplicationInfoTool::class,
        'config_inspector' => ConfigInspectorTool::class,
        'console_command_inspector' => ConsoleCommandInspectorTool::class,
        'database_query' => DatabaseQueryTool::class,
        'database_schema' => DatabaseSchemaTool::class,
        'dev_server' => DevServerTool::class,
        'env_inspector' => EnvInspectorTool::class,
        'log_inspector' => LogInspectorTool::class,
        'middleware_inspector' => MiddlewareInspectorTool::class,
        'migration_inspector' => MigrationInspectorTool::class,
        'model_inspector' => ModelInspectorTool::class,
        'performance_profiler' => PerformanceProfilerTool::class,
        'route_inspector' => RouteInspectorTool::class,
        'semantic_search' => SemanticSearchTool::class,
        'service_inspector' => ServiceInspectorTool::class,
        'tinker' => TinkerTool::class,
    ];

    private string $logFile;

    /** @var array<string, string> Tools that failed to resolve, with reasons */
    private array $unavailableTools = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly TransportInterface $transport,
        private readonly LoggerInterface $logger,
        private readonly string $serverName = 'Yii3 AI Boost',
        private readonly string $serverVersion = self::VERSION,
        private readonly array $enabledTools = [],
        private readonly array $additionalTools = [],
    ) {
        $logDir = sys_get_temp_dir() . '/mcp-server';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0700, true);
        }
        $this->logFile = $logDir . '/mcp-requests.log';

        $this->log('=== MCP Server Initialized ===');
    }

    /**
     * Start the MCP server listen loop.
     */
    public function start(): void
    {
        $this->transport->listen(fn(string $request): string => $this->handleRequest($request));
    }

    /**
     * Handle an incoming JSON-RPC request.
     */
    public function handleRequest(string $request): string
    {
        $decoded = null;

        try {
            $decoded = json_decode($request, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse(null, -32700, 'Parse error');
            }

            if (!isset($decoded['method'])) {
                return $this->errorResponse($decoded['id'] ?? null, -32600, 'Invalid Request');
            }

            $method = $decoded['method'];
            $params = $decoded['params'] ?? [];
            $id = $decoded['id'] ?? null;
            $isNotification = !array_key_exists('id', $decoded);

            $this->log("Method: $method" . ($isNotification ? ' (notification)' : ''));

            if ($isNotification) {
                $this->handleNotification($method, $params);
                return '';
            }

            $result = $this->dispatch($method, $params);

            return json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->log('Exception: ' . $e->getMessage(), 'ERROR');
            fwrite(STDERR, '[MCP Exception] ' . $e->getMessage() . "\n");

            return $this->errorResponse(
                $decoded['id'] ?? null,
                -32603,
                'Internal error',
                ['message' => $e->getMessage()],
            );
        }
    }

    private function dispatch(string $method, array $params): mixed
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'tools/list' => $this->listTools(),
            'tools/call' => $this->callTool(
                $params['name'] ?? '',
                $params['arguments'] ?? [],
            ),
            default => throw new \RuntimeException("Unknown method: $method"),
        };
    }

    private function handleNotification(string $method, array $params): void
    {
        $this->log("Notification: $method");
    }

    private function initialize(array $params): array
    {
        $protocolVersion = $params['protocolVersion'] ?? '2025-11-25';

        return [
            'protocolVersion' => $protocolVersion,
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ];
    }

    private function listTools(): array
    {
        $tools = [];
        $this->unavailableTools = [];

        $toolMap = $this->getFullToolMap();

        foreach ($toolMap as $name => $class) {
            if (!($this->enabledTools[$name] ?? true)) {
                $this->unavailableTools[$name] = 'Disabled in configuration';
                continue;
            }

            if (!class_exists($class)) {
                $this->unavailableTools[$name] = "Class $class not found";
                continue;
            }

            try {
                /** @var ToolInterface $tool */
                $tool = $this->container->get($class);
                $tools[] = [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'inputSchema' => $tool->getInputSchema(),
                ];
            } catch (\Throwable $e) {
                $this->unavailableTools[$name] = $e->getMessage();
                $this->log("Cannot resolve tool $name: " . $e->getMessage(), 'WARN');
            }
        }

        $this->log('Listed ' . count($tools) . ' tools, ' . count($this->unavailableTools) . ' unavailable');

        return ['tools' => $tools];
    }

    private function callTool(string $name, array $arguments): array
    {
        $toolMap = $this->getFullToolMap();

        if (!isset($toolMap[$name])) {
            throw new \RuntimeException("Unknown tool: $name");
        }

        if (!($this->enabledTools[$name] ?? true)) {
            throw new \RuntimeException("Tool '$name' is disabled in configuration");
        }

        $class = $toolMap[$name];

        /** @var ToolInterface $tool */
        $tool = $this->container->get($class);

        $this->log("Executing tool: $name");
        $result = $tool->execute($arguments);

        $text = is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ];
    }

    /**
     * Get the complete tool map (built-in + additional).
     *
     * @return array<string, class-string<ToolInterface>>
     */
    private function getFullToolMap(): array
    {
        return array_merge(self::TOOL_MAP, $this->additionalTools);
    }

    /**
     * Get tools that could not be loaded and their reasons.
     *
     * @return array<string, string>
     */
    public function getUnavailableTools(): array
    {
        return $this->unavailableTools;
    }

    private function errorResponse(?int $id, int $code, string $message, ?array $data = null): string
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ], JSON_THROW_ON_ERROR);
    }

    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] [$level] $message\n", FILE_APPEND);
    }
}
