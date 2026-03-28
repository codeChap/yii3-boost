<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Transport;

/**
 * STDIO Transport for MCP Protocol.
 *
 * Implements the Model Context Protocol using standard input/output.
 * Each message is a complete JSON string followed by newline.
 */
final class StdioTransport implements TransportInterface
{
    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    private readonly string $logFile;

    public function __construct(?string $basePath = null)
    {
        $stdin = fopen('php://stdin', 'r');
        $stdout = fopen('php://stdout', 'w');

        if ($stdin === false || $stdout === false) {
            throw new \RuntimeException('Failed to open STDIO streams');
        }

        $this->stdin = $stdin;
        $this->stdout = $stdout;

        stream_set_blocking($this->stdin, true);
        stream_set_timeout($this->stdin, 0);

        $this->logFile = $this->resolveLogFile($basePath);
        $this->log('StdioTransport initialized');
    }

    public function listen(callable $handler): void
    {
        $this->log('Starting MCP server listener');

        while (true) {
            $read = [$this->stdin];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, null);

            if ($ready === false) {
                $this->log('stream_select failed - exiting', 'ERROR');
                break;
            }

            $line = fgets($this->stdin);

            if ($line === false) {
                if (feof($this->stdin)) {
                    $this->log('Client disconnected (EOF received)');
                    break;
                }
                $this->log('Failed to read from stdin', 'ERROR');
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $preview = substr($line, 0, 200) . (strlen($line) > 200 ? '...' : '');
            $this->log("Received request: $preview", 'DEBUG');

            try {
                $response = $handler($line);

                if ($response !== '') {
                    $responsePreview = substr($response, 0, 200) . (strlen($response) > 200 ? '...' : '');
                    $this->log("Sending response: $responsePreview", 'DEBUG');
                    fwrite($this->stdout, $response . "\n");
                    fflush($this->stdout);
                } else {
                    $this->log('Handler returned empty response (notification)', 'DEBUG');
                }
            } catch (\Throwable $e) {
                $this->log('Handler exception: ' . $e->getMessage(), 'ERROR');
                fwrite(STDERR, '[MCP ERROR] ' . $e->getMessage() . "\n");
            }
        }

        $this->log('MCP server listener stopped');
    }

    public function __destruct()
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }
    }

    private function resolveLogFile(?string $basePath): string
    {
        if ($basePath !== null) {
            $dir = $basePath . '/runtime/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                return $dir . '/mcp-transport.log';
            }
        }

        $dir = sys_get_temp_dir() . '/mcp-server';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/mcp-transport.log';
    }

    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($this->logFile, "[$timestamp] [$level] $message\n", FILE_APPEND);
    }
}
