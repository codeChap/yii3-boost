<?php

declare(strict_types=1);

namespace codechap\yii3boost\tests\Mcp\Transport;

use codechap\yii3boost\Mcp\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

class StdioTransportTest extends TestCase
{
    public function testConstructorDoesNotThrow(): void
    {
        // StdioTransport opens php://stdin and php://stdout in the constructor.
        // In a PHPUnit process these streams are available, so the constructor
        // should complete without throwing.
        $transport = new StdioTransport();

        // If we reach this point, construction succeeded
        $this->assertInstanceOf(StdioTransport::class, $transport);
    }
}
