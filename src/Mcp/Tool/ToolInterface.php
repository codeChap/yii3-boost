<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

/**
 * Contract for all MCP tools.
 *
 * Tools provide specific capabilities that AI assistants can invoke
 * to inspect and interact with a Yii3 application.
 */
interface ToolInterface
{
    /**
     * Get the tool name (used in MCP tools/call).
     */
    public function getName(): string;

    /**
     * Get a human-readable description of what the tool does.
     */
    public function getDescription(): string;

    /**
     * Get the JSON Schema for the tool's input parameters.
     */
    public function getInputSchema(): array;

    /**
     * Execute the tool with given arguments.
     *
     * @param array $arguments Validated input arguments
     * @return mixed Result data (will be JSON-encoded for MCP response)
     */
    public function execute(array $arguments): mixed;
}
