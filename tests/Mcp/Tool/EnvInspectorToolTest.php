<?php

declare(strict_types=1);

namespace codechap\yii3boost\tests\Mcp\Tool;

use codechap\yii3boost\Mcp\Tool\EnvInspectorTool;
use PHPUnit\Framework\TestCase;

class EnvInspectorToolTest extends TestCase
{
    private EnvInspectorTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new EnvInspectorTool();
    }

    public function testGetName(): void
    {
        $this->assertSame('env_inspector', $this->tool->getName());
    }

    public function testExecuteReturnsEnvVars(): void
    {
        $result = $this->tool->execute(['include' => ['env_vars']]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('env_vars', $result);
        $this->assertIsArray($result['env_vars']);
    }

    public function testExecuteReturnsExtensions(): void
    {
        $result = $this->tool->execute(['include' => ['extensions']]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('extensions', $result);
        $this->assertArrayHasKey('count', $result['extensions']);
        $this->assertArrayHasKey('list', $result['extensions']);
        $this->assertIsInt($result['extensions']['count']);
        $this->assertIsArray($result['extensions']['list']);
        $this->assertGreaterThan(0, $result['extensions']['count']);
    }

    public function testSensitiveVarsAreSanitized(): void
    {
        // Set a sensitive env var for the duration of this test
        $originalValue = getenv('TEST_PASSWORD_SECRET');
        putenv('TEST_PASSWORD_SECRET=super_secret_value');

        try {
            $result = $this->tool->execute([
                'include' => ['env_vars'],
                'filter' => 'TEST_PASSWORD',
            ]);

            $this->assertArrayHasKey('env_vars', $result);

            // The key should exist but the value should be redacted
            // because "password" is in the key name
            $this->assertArrayHasKey('TEST_PASSWORD_SECRET', $result['env_vars']);
            $this->assertSame('***REDACTED***', $result['env_vars']['TEST_PASSWORD_SECRET']);
        } finally {
            // Restore original state
            if ($originalValue === false) {
                putenv('TEST_PASSWORD_SECRET');
            } else {
                putenv("TEST_PASSWORD_SECRET=$originalValue");
            }
        }
    }
}
