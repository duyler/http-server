<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Socket\StreamSocketResource;
use PHPUnit\Framework\TestCase;

final class KeepAliveTest extends TestCase
{
    public function testConnectionStartsWithoutKeepAlive(): void
    {
        $connection = $this->createConnection();

        $this->assertFalse($connection->isKeepAlive());
    }

    public function testCanEnableKeepAlive(): void
    {
        $connection = $this->createConnection();

        $connection->setKeepAlive(true);

        $this->assertTrue($connection->isKeepAlive());
    }

    public function testCanDisableKeepAlive(): void
    {
        $connection = $this->createConnection();

        $connection->setKeepAlive(true);
        $this->assertTrue($connection->isKeepAlive());

        $connection->setKeepAlive(false);
        $this->assertFalse($connection->isKeepAlive());
    }

    public function testTracksRequestCount(): void
    {
        $connection = $this->createConnection();

        $this->assertSame(0, $connection->getRequestCount());

        $connection->incrementRequestCount();
        $this->assertSame(1, $connection->getRequestCount());

        $connection->incrementRequestCount();
        $this->assertSame(2, $connection->getRequestCount());
    }

    public function testRequestCountPersistsAcrossKeepAliveRequests(): void
    {
        $connection = $this->createConnection();
        $connection->setKeepAlive(true);

        $connection->incrementRequestCount();
        $connection->clearBuffer();

        $this->assertSame(1, $connection->getRequestCount());
        $this->assertTrue($connection->isKeepAlive());
    }

    public function testUpdatesActivityTime(): void
    {
        $connection = $this->createConnection();

        $initialTime = $connection->getLastActivityTime();

        usleep(10000); // 10ms

        $connection->updateActivity();

        $newTime = $connection->getLastActivityTime();

        $this->assertGreaterThan($initialTime, $newTime);
    }

    public function testDetectsTimeout(): void
    {
        $connection = $this->createConnection();

        $this->assertFalse($connection->isTimedOut(timeout: 1));

        usleep(10000);

        $this->assertFalse($connection->isTimedOut(timeout: 1));
    }

    public function testAppendToBufferUpdatesActivity(): void
    {
        $connection = $this->createConnection();

        $initialTime = $connection->getLastActivityTime();

        usleep(10000);

        $connection->appendToBuffer('test data');

        $newTime = $connection->getLastActivityTime();

        $this->assertGreaterThan($initialTime, $newTime);
        $this->assertSame('test data', $connection->getBuffer());
    }

    public function testClearBufferPreservesKeepAliveState(): void
    {
        $connection = $this->createConnection();
        $connection->setKeepAlive(true);
        $connection->appendToBuffer('some data');

        $this->assertSame('some data', $connection->getBuffer());
        $this->assertTrue($connection->isKeepAlive());

        $connection->clearBuffer();

        $this->assertSame('', $connection->getBuffer());
        $this->assertTrue($connection->isKeepAlive());
    }

    public function testTracksRequestStartTime(): void
    {
        $connection = $this->createConnection();

        $this->assertNull($connection->getRequestStartTime());

        $connection->startRequestTimer();

        $this->assertIsFloat($connection->getRequestStartTime());
        $this->assertGreaterThan(0, $connection->getRequestStartTime());
    }

    public function testDetectsRequestTimeout(): void
    {
        $connection = $this->createConnection();

        $connection->startRequestTimer();

        $this->assertFalse($connection->isRequestTimedOut(timeout: 1));
    }

    public function testClearBufferResetsRequestTimer(): void
    {
        $connection = $this->createConnection();

        $connection->startRequestTimer();
        $this->assertNotNull($connection->getRequestStartTime());

        $connection->clearBuffer();

        $this->assertNull($connection->getRequestStartTime());
    }

    private function createConnection(): Connection
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->fail('Failed to create socket');
        }

        return new Connection(new StreamSocketResource($socket), '127.0.0.1', 8080);
    }
}
