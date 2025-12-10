<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeepAliveTest extends TestCase
{
    #[Test]
    public function connection_starts_without_keep_alive(): void
    {
        $connection = $this->createConnection();

        $this->assertFalse($connection->isKeepAlive());
    }

    #[Test]
    public function can_enable_keep_alive(): void
    {
        $connection = $this->createConnection();

        $connection->setKeepAlive(true);

        $this->assertTrue($connection->isKeepAlive());
    }

    #[Test]
    public function can_disable_keep_alive(): void
    {
        $connection = $this->createConnection();

        $connection->setKeepAlive(true);
        $this->assertTrue($connection->isKeepAlive());

        $connection->setKeepAlive(false);
        $this->assertFalse($connection->isKeepAlive());
    }

    #[Test]
    public function tracks_request_count(): void
    {
        $connection = $this->createConnection();

        $this->assertSame(0, $connection->getRequestCount());

        $connection->incrementRequestCount();
        $this->assertSame(1, $connection->getRequestCount());

        $connection->incrementRequestCount();
        $this->assertSame(2, $connection->getRequestCount());
    }

    #[Test]
    public function request_count_persists_across_keep_alive_requests(): void
    {
        $connection = $this->createConnection();
        $connection->setKeepAlive(true);

        $connection->incrementRequestCount();
        $connection->clearBuffer();

        $this->assertSame(1, $connection->getRequestCount());
        $this->assertTrue($connection->isKeepAlive());
    }

    #[Test]
    public function updates_activity_time(): void
    {
        $connection = $this->createConnection();

        $initialTime = $connection->getLastActivityTime();

        usleep(10000); // 10ms

        $connection->updateActivity();

        $newTime = $connection->getLastActivityTime();

        $this->assertGreaterThan($initialTime, $newTime);
    }

    #[Test]
    public function detects_timeout(): void
    {
        $connection = $this->createConnection();

        $this->assertFalse($connection->isTimedOut(timeout: 1));

        usleep(10000);

        $this->assertFalse($connection->isTimedOut(timeout: 1));
    }

    #[Test]
    public function append_to_buffer_updates_activity(): void
    {
        $connection = $this->createConnection();

        $initialTime = $connection->getLastActivityTime();

        usleep(10000);

        $connection->appendToBuffer('test data');

        $newTime = $connection->getLastActivityTime();

        $this->assertGreaterThan($initialTime, $newTime);
        $this->assertSame('test data', $connection->getBuffer());
    }

    #[Test]
    public function clear_buffer_preserves_keep_alive_state(): void
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

    #[Test]
    public function tracks_request_start_time(): void
    {
        $connection = $this->createConnection();

        $this->assertNull($connection->getRequestStartTime());

        $connection->startRequestTimer();

        $this->assertIsFloat($connection->getRequestStartTime());
        $this->assertGreaterThan(0, $connection->getRequestStartTime());
    }

    #[Test]
    public function detects_request_timeout(): void
    {
        $connection = $this->createConnection();

        $connection->startRequestTimer();

        $this->assertFalse($connection->isRequestTimedOut(timeout: 1));
    }

    #[Test]
    public function clear_buffer_resets_request_timer(): void
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

        return new Connection($socket, '127.0.0.1', 8080);
    }
}
