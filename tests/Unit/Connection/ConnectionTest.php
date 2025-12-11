<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    /** @var resource */
    private mixed $socket;
    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->connection = new Connection($this->socket, '127.0.0.1', 12345);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    #[Test]
    public function returns_socket_resource(): void
    {
        $this->assertSame($this->socket, $this->connection->getSocket());
    }

    #[Test]
    public function returns_remote_address(): void
    {
        $this->assertSame('127.0.0.1', $this->connection->getRemoteAddress());
    }

    #[Test]
    public function returns_remote_port(): void
    {
        $this->assertSame(12345, $this->connection->getRemotePort());
    }

    #[Test]
    public function buffer_is_empty_initially(): void
    {
        $this->assertSame('', $this->connection->getBuffer());
    }

    #[Test]
    public function appends_data_to_buffer(): void
    {
        $this->connection->appendToBuffer('Hello');
        $this->assertSame('Hello', $this->connection->getBuffer());

        $this->connection->appendToBuffer(' World');
        $this->assertSame('Hello World', $this->connection->getBuffer());
    }

    #[Test]
    public function clears_buffer(): void
    {
        $this->connection->appendToBuffer('test data');
        $this->connection->clearBuffer();

        $this->assertSame('', $this->connection->getBuffer());
    }

    #[Test]
    public function tracks_request_count(): void
    {
        $this->assertSame(0, $this->connection->getRequestCount());

        $this->connection->incrementRequestCount();
        $this->assertSame(1, $this->connection->getRequestCount());

        $this->connection->incrementRequestCount();
        $this->assertSame(2, $this->connection->getRequestCount());
    }

    #[Test]
    public function updates_last_activity_time(): void
    {
        $initialTime = $this->connection->getLastActivityTime();

        usleep(10000);
        $this->connection->updateActivity();

        $this->assertGreaterThan($initialTime, $this->connection->getLastActivityTime());
    }

    #[Test]
    public function detects_timeout(): void
    {
        $this->assertFalse($this->connection->isTimedOut(1));

        sleep(2);

        $this->assertTrue($this->connection->isTimedOut(1));
    }

    #[Test]
    public function manages_keep_alive_flag(): void
    {
        $this->assertFalse($this->connection->isKeepAlive());

        $this->connection->setKeepAlive(true);
        $this->assertTrue($this->connection->isKeepAlive());

        $this->connection->setKeepAlive(false);
        $this->assertFalse($this->connection->isKeepAlive());
    }

    #[Test]
    public function writes_data(): void
    {
        $written = $this->connection->write('test data');

        $this->assertIsInt($written);
        $this->assertGreaterThan(0, $written);
    }

    #[Test]
    public function reads_data(): void
    {
        fwrite($this->socket, 'test content');
        rewind($this->socket);

        $data = $this->connection->read(4);

        $this->assertSame('test', $data);
    }

    #[Test]
    public function closes_connection(): void
    {
        $this->connection->close();

        $this->assertFalse(is_resource($this->socket));
    }
}
