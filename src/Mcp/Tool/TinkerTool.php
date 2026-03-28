<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

/**
 * Tinker Tool
 *
 * Execute arbitrary PHP code in the running Yii3 application context.
 * The most powerful tool -- lets AI agents do anything the specialized tools can't.
 */
final class TinkerTool extends AbstractTool
{
    /**
     * Maximum allowed code length in characters.
     */
    private const MAX_CODE_LENGTH = 10000;

    /**
     * Default execution timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 5;

    /**
     * Maximum execution timeout in seconds.
     */
    private const MAX_TIMEOUT = 30;

    /**
     * Maximum output length in bytes.
     */
    private const MAX_OUTPUT_LENGTH = 102400;

    /**
     * Dangerous function patterns to block.
     *
     * @var array<string>
     */
    private const DANGEROUS_PATTERNS = [
        '/\b(exit|die)\s*\(/i',
        '/\b(passthru|shell_exec|system|proc_open|popen|pcntl_exec)\s*\(/i',
        '/\bexec\s*\(/i',
        '/\b(call_user_func|call_user_func_array)\s*\(/i',
        '/\b(create_function)\s*\(/i',
    ];

    public function getName(): string
    {
        return 'tinker';
    }

    public function getDescription(): string
    {
        return 'Execute arbitrary PHP code in the Yii3 application context';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'string',
                    'description' => 'PHP code to execute (without <?php tag)',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Max execution time in seconds (default: 5, max: 30)',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $code = $arguments['code'] ?? '';
        $timeout = $arguments['timeout'] ?? self::DEFAULT_TIMEOUT;
        $timeout = min(max(1, (int) $timeout), self::MAX_TIMEOUT);

        $this->validateCode($code);

        $startTime = microtime(true);

        try {
            $result = $this->executeCode($code, $timeout);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $returnValue = $this->formatReturnValue($result['return_value']);
            $output = $this->truncateOutput($result['output'], self::MAX_OUTPUT_LENGTH);

            return $this->sanitize([
                'success' => true,
                'return_value' => $returnValue,
                'output' => $output,
                'duration_ms' => $duration,
                'type' => $result['type'],
            ]);
        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return $this->sanitize([
                'success' => false,
                'error' => $e->getMessage(),
                'error_class' => $e::class,
                'line' => $e->getLine(),
                'duration_ms' => $duration,
            ]);
        }
    }

    /**
     * Validate code before execution.
     *
     * @param string $code PHP code to validate
     * @throws \Exception If code is invalid or contains dangerous constructs
     */
    private function validateCode(string $code): void
    {
        if (empty(trim($code))) {
            throw new \Exception('Code cannot be empty');
        }

        if (strlen($code) > self::MAX_CODE_LENGTH) {
            throw new \Exception(
                'Code exceeds maximum length of ' . self::MAX_CODE_LENGTH . ' characters'
            );
        }

        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                throw new \Exception(
                    'Code contains a blocked function. To prevent crashing the MCP server process, '
                    . 'the following are not allowed: exit, die, exec, passthru, shell_exec, system, '
                    . 'proc_open, popen, pcntl_exec, call_user_func, call_user_func_array, create_function'
                );
            }
        }
    }

    /**
     * Execute code in an isolated scope.
     *
     * @param string $code PHP code to execute
     * @param int $timeout Max execution time in seconds
     * @return array{return_value: mixed, output: string, type: string}
     */
    private function executeCode(string $code, int $timeout): array
    {
        $previousTimeout = (int) ini_get('max_execution_time');
        set_time_limit($timeout);

        ob_start();

        try {
            // Execute in isolated closure scope so eval'd code can't access $this or local vars
            $returnValue = (static function () use ($code) {
                // Try as expression first (return $code;)
                // If the code is a statement (echo, etc.), this throws ParseError
                try {
                    return eval('return ' . $code . ';');
                } catch (\ParseError $e) {
                    // Not a valid expression -- execute as statement(s)
                    // Ensure trailing semicolon for statement execution
                    $stmtCode = rtrim($code);
                    if (!preg_match('/[;\}\)]\s*$/', $stmtCode)) {
                        $stmtCode .= ';';
                    }
                    return eval($stmtCode);
                }
            })();

            $output = ob_get_clean() ?: '';
        } catch (\Throwable $e) {
            ob_end_clean();
            set_time_limit($previousTimeout);
            throw $e;
        }

        set_time_limit($previousTimeout);

        return [
            'return_value' => $returnValue,
            'output' => $output,
            'type' => is_object($returnValue) ? $returnValue::class : gettype($returnValue),
        ];
    }

    /**
     * Format a return value for output.
     *
     * @param mixed $value The value to format
     * @return mixed Formatted value
     */
    private function formatReturnValue(mixed $value): mixed
    {
        if (is_null($value) || is_scalar($value) || is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return print_r($value, true);
        }

        return (string) $value;
    }

    /**
     * Truncate output to a maximum length.
     *
     * @param string $output Output string
     * @param int $maxLength Maximum allowed length
     * @return string Truncated output
     */
    private function truncateOutput(string $output, int $maxLength): string
    {
        if (strlen($output) > $maxLength) {
            return substr($output, 0, $maxLength) . "\n... [output truncated at " . $maxLength . " bytes]";
        }

        return $output;
    }
}
