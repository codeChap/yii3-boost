<?php

declare(strict_types=1);

namespace codechap\yii3boost\Command;

use codechap\yii3boost\Mcp\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start the MCP server for AI assistant communication.
 *
 * This command enters a JSON-RPC listen loop on STDIN/STDOUT.
 * STDOUT is reserved exclusively for JSON-RPC — all logging goes to STDERR or files.
 */
#[AsCommand(
    name: 'boost:mcp',
    description: 'Start the Yii3 AI Boost MCP server',
)]
final class McpCommand extends Command
{
    public function __construct(
        private readonly Server $server,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // CRITICAL: Protect STDOUT from any non-JSON-RPC output.
        // The MCP protocol requires STDOUT to contain only JSON-RPC messages.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        // Clear any buffered output from framework bootstrap
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Redirect PHP errors to STDERR
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
            fwrite(STDERR, "[PHP $errno] $errstr in $errfile:$errline\n");
            return true;
        });

        // Protect against Logger shutdown function writing to STDOUT
        register_shutdown_function(function (): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        });

        // Interactive mode hint
        if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
            fwrite(STDERR, "Yii3 AI Boost MCP Server starting...\n");
            fwrite(STDERR, "  Waiting for JSON-RPC on STDIN (Ctrl+C to exit)\n\n");
        }

        try {
            $this->server->start();
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'MCP Server Error: ' . $e->getMessage() . "\n");
            return Command::FAILURE;
        }
    }
}
