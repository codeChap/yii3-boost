<?php

declare(strict_types=1);

namespace codechap\yii3boost\tests\Mcp\Tool;

use codechap\yii3boost\Mcp\Tool\AbstractTool;
use PHPUnit\Framework\TestCase;

/**
 * Concrete subclass to expose protected methods for testing.
 */
class ConcreteTestTool extends AbstractTool
{
    public function getName(): string
    {
        return 'test_tool';
    }

    public function getDescription(): string
    {
        return 'A tool for testing AbstractTool methods';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments): mixed
    {
        return [];
    }

    /**
     * Expose sanitize() for testing.
     */
    public function exposeSanitize(mixed $data): mixed
    {
        return $this->sanitize($data);
    }

    /**
     * Expose getClassNameFromFile() for testing.
     */
    public function exposeGetClassNameFromFile(string $file): ?string
    {
        return $this->getClassNameFromFile($file);
    }
}

class AbstractToolTest extends TestCase
{
    private ConcreteTestTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ConcreteTestTool();
    }

    public function testSanitizeRedactsSensitiveKeys(): void
    {
        $data = [
            'password' => 'my_password',
            'db_token' => 'abc123',
            'client_secret' => 'xyz789',
            'api_key' => 'key-12345',
            'private_key' => 'pk_test',
            'access_token' => 'at_value',
            'dsn' => 'mysql://root@localhost/db',
        ];

        $result = $this->tool->exposeSanitize($data);

        $this->assertSame('***REDACTED***', $result['password']);
        $this->assertSame('***REDACTED***', $result['db_token']);
        $this->assertSame('***REDACTED***', $result['client_secret']);
        $this->assertSame('***REDACTED***', $result['api_key']);
        $this->assertSame('***REDACTED***', $result['private_key']);
        $this->assertSame('***REDACTED***', $result['access_token']);
        $this->assertSame('***REDACTED***', $result['dsn']);
    }

    public function testSanitizePreservesNormalKeys(): void
    {
        $data = [
            'name' => 'MyApp',
            'version' => '1.0.0',
            'debug' => true,
            'port' => 8080,
            'features' => ['routing', 'caching'],
        ];

        $result = $this->tool->exposeSanitize($data);

        $this->assertSame('MyApp', $result['name']);
        $this->assertSame('1.0.0', $result['version']);
        $this->assertTrue($result['debug']);
        $this->assertSame(8080, $result['port']);
        $this->assertSame(['routing', 'caching'], $result['features']);
    }

    public function testSanitizeHandlesNestedArrays(): void
    {
        $data = [
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'password' => 'secret123',
                'connection_string' => 'mysql://root:secret@localhost/db',
            ],
            'app' => [
                'name' => 'TestApp',
                'settings' => [
                    'api_key' => 'nested-key-value',
                    'timeout' => 30,
                ],
            ],
        ];

        $result = $this->tool->exposeSanitize($data);

        // Nested sensitive keys should be redacted
        $this->assertSame('localhost', $result['database']['host']);
        $this->assertSame(3306, $result['database']['port']);
        $this->assertSame('***REDACTED***', $result['database']['password']);
        $this->assertSame('***REDACTED***', $result['database']['connection_string']);

        // Deeper nested sensitive keys should also be redacted
        $this->assertSame('TestApp', $result['app']['name']);
        $this->assertSame('***REDACTED***', $result['app']['settings']['api_key']);
        $this->assertSame(30, $result['app']['settings']['timeout']);
    }

    public function testGetClassNameFromFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'php_test_');
        $this->assertNotFalse($tmpFile);

        file_put_contents($tmpFile, <<<'PHP'
<?php

namespace App\Models;

class UserProfile
{
    public function getName(): string
    {
        return 'test';
    }
}
PHP);

        try {
            $className = $this->tool->exposeGetClassNameFromFile($tmpFile);
            $this->assertSame('App\Models\UserProfile', $className);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @skip Requires filesystem setup with real class hierarchy
     */
    public function testScanForSubclasses(): void
    {
        $this->markTestSkipped('Requires filesystem setup with loadable class hierarchy');
    }
}
