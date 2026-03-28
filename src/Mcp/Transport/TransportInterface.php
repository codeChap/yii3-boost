<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Transport;

/**
 * Contract for MCP transport implementations.
 *
 * Transports handle the I/O for JSON-RPC communication between
 * the MCP server and AI assistant clients.
 */
interface TransportInterface
{
    /**
     * Start listening for JSON-RPC requests.
     *
     * Enters a loop reading requests and passing them to the handler.
     * The handler returns a JSON-RPC response string (or empty for notifications).
     *
     * @param callable(string): string $handler Request handler
     */
    public function listen(callable $handler): void;
}
