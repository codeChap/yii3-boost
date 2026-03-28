<?php

declare(strict_types=1);

namespace codechap\yii3boost\tests\Mcp;

use codechap\yii3boost\Mcp\Server;
use codechap\yii3boost\Mcp\Tool\EnvInspectorTool;
use codechap\yii3boost\Mcp\Tool\ToolInterface;
use codechap\yii3boost\Mcp\Transport\TransportInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class ServerTest extends TestCase
{
    private function createServer(
        ?ContainerInterface $container = null,
        array $enabledTools = [],
        array $additionalTools = [],
    ): Server {
        $container ??= $this->createMock(ContainerInterface::class);
        $transport = $this->createMock(TransportInterface::class);
        $logger = new NullLogger();

        return new Server(
            container: $container,
            transport: $transport,
            logger: $logger,
            serverName: 'Test Server',
            serverVersion: '1.0.0-test',
            enabledTools: $enabledTools,
            additionalTools: $additionalTools,
        );
    }

    private function jsonRpcRequest(string $method, array $params = [], int $id = 1): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $id,
        ]);
    }

    public function testInitializeReturnsServerInfo(): void
    {
        $server = $this->createServer();

        $response = $server->handleRequest(
            $this->jsonRpcRequest('initialize', ['protocolVersion' => '2025-11-25']),
        );

        $decoded = json_decode($response, true);

        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(1, $decoded['id']);
        $this->assertArrayHasKey('result', $decoded);

        $result = $decoded['result'];
        $this->assertSame('2025-11-25', $result['protocolVersion']);
        $this->assertArrayHasKey('serverInfo', $result);
        $this->assertSame('Test Server', $result['serverInfo']['name']);
        $this->assertSame('1.0.0-test', $result['serverInfo']['version']);
        $this->assertArrayHasKey('capabilities', $result);
        $this->assertArrayHasKey('tools', $result['capabilities']);
    }

    public function testToolsListReturnsTools(): void
    {
        $mockTool = $this->createMock(ToolInterface::class);
        $mockTool->method('getName')->willReturn('env_inspector');
        $mockTool->method('getDescription')->willReturn('Inspect environment');
        $mockTool->method('getInputSchema')->willReturn([
            'type' => 'object',
            'properties' => [],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with(EnvInspectorTool::class)
            ->willReturn($mockTool);

        // Disable all built-in tools except env_inspector
        $enabledTools = [
            'application_info' => false,
            'config_inspector' => false,
            'console_command_inspector' => false,
            'database_query' => false,
            'database_schema' => false,
            'dev_server' => false,
            'env_inspector' => true,
            'log_inspector' => false,
            'middleware_inspector' => false,
            'migration_inspector' => false,
            'model_inspector' => false,
            'performance_profiler' => false,
            'route_inspector' => false,
            'semantic_search' => false,
            'service_inspector' => false,
            'tinker' => false,
        ];

        $server = $this->createServer($container, $enabledTools);

        $response = $server->handleRequest(
            $this->jsonRpcRequest('tools/list'),
        );

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('result', $decoded);
        $tools = $decoded['result']['tools'];
        $this->assertCount(1, $tools);
        $this->assertSame('env_inspector', $tools[0]['name']);
        $this->assertSame('Inspect environment', $tools[0]['description']);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
    }

    public function testToolsCallExecutesTool(): void
    {
        $mockTool = $this->createMock(ToolInterface::class);
        $mockTool->method('getName')->willReturn('env_inspector');
        $mockTool->method('execute')
            ->with(['include' => ['extensions']])
            ->willReturn(['extensions' => ['count' => 5, 'list' => ['pdo', 'json', 'mbstring', 'curl', 'openssl']]]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with(EnvInspectorTool::class)
            ->willReturn($mockTool);

        $server = $this->createServer($container);

        $response = $server->handleRequest(
            $this->jsonRpcRequest('tools/call', [
                'name' => 'env_inspector',
                'arguments' => ['include' => ['extensions']],
            ]),
        );

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('result', $decoded);
        $this->assertArrayHasKey('content', $decoded['result']);
        $this->assertCount(1, $decoded['result']['content']);
        $this->assertSame('text', $decoded['result']['content'][0]['type']);

        $text = json_decode($decoded['result']['content'][0]['text'], true);
        $this->assertArrayHasKey('extensions', $text);
        $this->assertSame(5, $text['extensions']['count']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $server = $this->createServer();

        $response = $server->handleRequest(
            $this->jsonRpcRequest('nonexistent/method'),
        );

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(-32603, $decoded['error']['code']);
        $this->assertSame('Internal error', $decoded['error']['message']);
    }

    public function testParseErrorOnInvalidJson(): void
    {
        $server = $this->createServer();

        $response = $server->handleRequest('{invalid json!!!');

        $decoded = json_decode($response, true);

        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame(-32700, $decoded['error']['code']);
        $this->assertSame('Parse error', $decoded['error']['message']);
    }

    public function testNotificationReturnsEmpty(): void
    {
        $server = $this->createServer();

        // A notification has no "id" field
        $request = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => [],
        ]);

        $response = $server->handleRequest($request);

        $this->assertSame('', $response);
    }
}
