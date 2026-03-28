<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Yiisoft\Aliases\Aliases;

/**
 * DevServerTool - Start, stop, and make requests to the Yii3 built-in dev server.
 *
 * Allows AI agents to spin up `php yii serve`, hit routes, and capture
 * the HTTP response together with any PHP errors/warnings from stderr.
 */
class DevServerTool extends AbstractTool
{
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 8080;
    private const MIN_PORT = 1024;
    private const MAX_PORT = 65535;
    private const DEFAULT_TIMEOUT = 10;
    private const MAX_TIMEOUT = 30;
    private const MAX_RESPONSE_LENGTH = 102400; // 100 KB
    private const SERVER_STARTUP_WAIT = 1; // seconds to wait for server to start

    /** @var resource|null */
    private static $process = null;

    /** @var array|null Pipes from proc_open [stdin, stdout, stderr] */
    private static ?array $pipes = null;

    /** @var string|null Bound host:port */
    private static ?string $boundAddress = null;

    /** @var string|null Temporary file for stderr capture */
    private static ?string $stderrFile = null;

    public function __construct(
        private readonly Aliases $aliases,
    ) {
    }

    public function getName(): string
    {
        return 'dev_server';
    }

    public function getDescription(): string
    {
        return 'Start/stop the Yii3 built-in dev server and make HTTP requests to it. '
             . 'Actions: start, stop, status, request. '
             . 'The "request" action auto-starts the server if needed.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'description' => 'Action to perform: start, stop, status, or request',
                    'enum' => ['start', 'stop', 'status', 'request'],
                ],
                'host' => [
                    'type' => 'string',
                    'description' => 'Host to bind to (default: 127.0.0.1)',
                ],
                'port' => [
                    'type' => 'integer',
                    'description' => 'Port to bind to (default: 8080)',
                ],
                'route' => [
                    'type' => 'string',
                    'description' => 'URL path to request, e.g. /api/v1/search (required for "request" action)',
                ],
                'method' => [
                    'type' => 'string',
                    'description' => 'HTTP method for request action (default: GET)',
                    'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Request timeout in seconds (default: 10, max: 30)',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $action = $arguments['action'] ?? '';

        return match ($action) {
            'start'   => $this->handleStart($arguments),
            'stop'    => $this->handleStop(),
            'status'  => $this->handleStatus(),
            'request' => $this->handleRequest($arguments),
            default   => ['error' => "Unknown action: $action. Use start, stop, status, or request."],
        };
    }

    /**
     * Start the dev server as a background process.
     */
    private function handleStart(array $arguments): array
    {
        if ($this->isRunning()) {
            return [
                'success' => true,
                'message' => 'Server is already running',
                'address' => self::$boundAddress,
            ];
        }

        $host = $arguments['host'] ?? self::DEFAULT_HOST;
        $port = (int) ($arguments['port'] ?? self::DEFAULT_PORT);

        // Safety: only allow localhost binding
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return ['error' => 'For safety, the server can only bind to localhost (127.0.0.1, localhost, or ::1).'];
        }

        // Validate port range
        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            return ['error' => "Port must be between " . self::MIN_PORT . " and " . self::MAX_PORT . "."];
        }

        // Check if the port is already in use
        $sock = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($sock) {
            fclose($sock);
            return ['error' => "Port $port is already in use on $host."];
        }

        // Create a temp file for stderr
        self::$stderrFile = tempnam(sys_get_temp_dir(), 'yii_serve_stderr_');

        $cmd = sprintf(
            'php yii serve %s',
            escapeshellarg("$host:$port")
        );

        $descriptors = [
            0 => ['pipe', 'r'],            // stdin
            1 => ['pipe', 'w'],            // stdout
            2 => ['file', self::$stderrFile, 'a'], // stderr -> file
        ];

        $process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->aliases->get('@root'),
            null
        );

        if (!is_resource($process)) {
            return ['error' => 'Failed to start dev server process.'];
        }

        // Make stdout non-blocking so we can check without hanging
        stream_set_blocking($pipes[1], false);

        self::$process = $process;
        self::$pipes = $pipes;
        self::$boundAddress = "$host:$port";

        // Wait briefly for the server to start
        sleep(self::SERVER_STARTUP_WAIT);

        // Verify the process is still running
        $status = proc_get_status(self::$process);
        if (!$status['running']) {
            $stderr = (string) file_get_contents(self::$stderrFile);
            $stdout = stream_get_contents($pipes[1]);
            $this->cleanup();
            return [
                'error' => 'Server process exited immediately.',
                'stdout' => trim((string) $stdout),
                'stderr' => trim((string) $stderr),
            ];
        }

        return [
            'success' => true,
            'message' => 'Dev server started',
            'address' => self::$boundAddress,
            'url' => "http://" . self::$boundAddress,
        ];
    }

    /**
     * Stop the running dev server.
     */
    private function handleStop(): array
    {
        if (!$this->isRunning()) {
            return [
                'success' => true,
                'message' => 'Server is not running (nothing to stop)',
            ];
        }

        $address = self::$boundAddress;
        $this->cleanup();

        return [
            'success' => true,
            'message' => 'Dev server stopped',
            'address' => $address,
        ];
    }

    /**
     * Report server status.
     */
    private function handleStatus(): array
    {
        $running = $this->isRunning();

        $result = [
            'running' => $running,
            'address' => $running ? self::$boundAddress : null,
            'url' => $running ? "http://" . self::$boundAddress : null,
        ];

        // Include recent stderr output if available
        if ($running && self::$stderrFile && file_exists(self::$stderrFile)) {
            $stderr = file_get_contents(self::$stderrFile);
            if ($stderr !== false && $stderr !== '') {
                // Tail: last 50 lines
                $lines = explode("\n", trim($stderr));
                $result['recent_output'] = implode("\n", array_slice($lines, -50));
            }
        }

        return $result;
    }

    /**
     * Make an HTTP request to the dev server.
     * Auto-starts the server if not running.
     */
    private function handleRequest(array $arguments): array
    {
        $route = $arguments['route'] ?? null;
        if (empty($route)) {
            return ['error' => 'The "route" parameter is required for the request action.'];
        }

        // Ensure route starts with /
        if ($route[0] !== '/') {
            $route = '/' . $route;
        }

        $method = strtoupper($arguments['method'] ?? 'GET');
        $timeout = min((int) ($arguments['timeout'] ?? self::DEFAULT_TIMEOUT), self::MAX_TIMEOUT);
        if ($timeout < 1) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        // Auto-start server if not running
        if (!$this->isRunning()) {
            $startResult = $this->handleStart($arguments);
            if (isset($startResult['error'])) {
                return $startResult;
            }
        }

        // Note the stderr file position before the request
        $stderrBefore = 0;
        if (self::$stderrFile && file_exists(self::$stderrFile)) {
            $stderrBefore = filesize(self::$stderrFile) ?: 0;
        }

        $url = "http://" . self::$boundAddress . $route;

        // Make the HTTP request using cURL
        $result = $this->doHttpRequest($url, $method, $timeout);

        // Capture any new stderr output (PHP errors/warnings)
        $serverErrors = '';
        if (self::$stderrFile && file_exists(self::$stderrFile)) {
            clearstatcache(true, self::$stderrFile);
            $stderrAfter = filesize(self::$stderrFile) ?: 0;
            $readLength = $stderrAfter - $stderrBefore;
            if ($readLength > 0) {
                $fh = fopen(self::$stderrFile, 'r');
                if ($fh) {
                    fseek($fh, $stderrBefore);
                    $serverErrors = (string) fread($fh, $readLength);
                    fclose($fh);
                }
            }
        }

        if (!empty($serverErrors)) {
            $result['server_errors'] = trim($serverErrors);
        }

        return $result;
    }

    /**
     * Perform an HTTP request using cURL.
     */
    private function doHttpRequest(string $url, string $method, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return $this->doHttpRequestFallback($url, $method, $timeout);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY => ($method === 'HEAD'),
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            return [
                'error' => "HTTP request failed: $error (code: $errno)",
                'url' => $url,
                'method' => $method,
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        /** @var string $response */
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Parse response headers
        $headers = [];
        foreach (explode("\r\n", trim($headerStr)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $val] = explode(':', $line, 2);
                $headers[trim($key)] = trim($val);
            }
        }

        // Truncate body if too large
        $truncated = false;
        if (strlen($body) > self::MAX_RESPONSE_LENGTH) {
            $body = substr($body, 0, self::MAX_RESPONSE_LENGTH);
            $truncated = true;
        }

        return [
            'success' => true,
            'url' => $url,
            'method' => $method,
            'http_status' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'body_length' => strlen($body),
            'truncated' => $truncated,
        ];
    }

    /**
     * Fallback HTTP request using file_get_contents.
     */
    private function doHttpRequestFallback(string $url, string $method, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => false,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return [
                'error' => 'HTTP request failed (file_get_contents)',
                'url' => $url,
                'method' => $method,
            ];
        }

        // Parse response headers from $http_response_header
        $httpCode = 0;
        $headers = [];
        /** @var string[]|null $http_response_header */
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $line, $m)) {
                    $httpCode = (int) $m[1];
                } elseif (str_contains($line, ':')) {
                    [$key, $val] = explode(':', $line, 2);
                    $headers[trim($key)] = trim($val);
                }
            }
        }

        // Truncate body if too large
        $truncated = false;
        if (strlen($body) > self::MAX_RESPONSE_LENGTH) {
            $body = substr($body, 0, self::MAX_RESPONSE_LENGTH);
            $truncated = true;
        }

        return [
            'success' => true,
            'url' => $url,
            'method' => $method,
            'http_status' => $httpCode,
            'headers' => $headers,
            'body' => $body,
            'body_length' => strlen($body),
            'truncated' => $truncated,
        ];
    }

    /**
     * Check whether the server process is still running.
     */
    private function isRunning(): bool
    {
        if (self::$process === null) {
            return false;
        }

        $status = proc_get_status(self::$process);
        return $status['running'];
    }

    /**
     * Kill the server process and clean up resources.
     */
    private function cleanup(): void
    {
        if (self::$process !== null) {
            // Get PID before closing pipes
            $status = proc_get_status(self::$process);
            $pid = $status['pid'];

            // Close pipes first to unblock proc_close
            if (self::$pipes !== null) {
                foreach (self::$pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                self::$pipes = null;
            }

            // Kill the process tree
            if ($pid > 0 && $status['running']) {
                if (PHP_OS_FAMILY === 'Windows') {
                    exec("taskkill /F /T /PID $pid 2>&1");
                } else {
                    // Kill all child processes via pkill, then the parent
                    exec("pkill -TERM -P $pid 2>/dev/null");
                    usleep(100000); // 100ms grace
                    exec("pkill -KILL -P $pid 2>/dev/null");
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGTERM);
                    } else {
                        exec("kill -TERM $pid 2>/dev/null");
                    }
                    usleep(100000);
                    if (function_exists('posix_kill')) {
                        posix_kill($pid, SIGKILL);
                    } else {
                        exec("kill -KILL $pid 2>/dev/null");
                    }
                }
            }

            proc_close(self::$process);
            self::$process = null;
        } else {
            // No process, just clean up pipes
            if (self::$pipes !== null) {
                foreach (self::$pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                self::$pipes = null;
            }
        }

        // Clean up stderr temp file
        if (self::$stderrFile !== null && file_exists(self::$stderrFile)) {
            @unlink(self::$stderrFile);
            self::$stderrFile = null;
        }

        self::$boundAddress = null;
    }

    /**
     * Destructor - ensure the server is stopped when the tool is destroyed.
     */
    public function __destruct()
    {
        if ($this->isRunning()) {
            $this->cleanup();
        }
    }
}
