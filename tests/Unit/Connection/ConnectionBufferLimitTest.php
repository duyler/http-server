<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConnectionBufferLimitTest extends TestCase
{
    /** @var resource */
    private mixed $socket;
    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $socketResource = new StreamSocketResource($this->socket);
        $this->connection = new Connection($socketResource, '127.0.0.1', 12345, 1024);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    #[Test]
    public function buffer_stays_within_limit(): void
    {
        $this->connection->appendToBuffer(str_repeat('A', 512));
        $this->connection->appendToBuffer(str_repeat('B', 256));

        $this->assertSame(768, strlen($this->connection->getBuffer()));
        $this->assertFalse($this->connection->isClosed());
    }

    #[Test]
    public function buffer_at_exact_limit_does_not_close(): void
    {
        $this->connection->appendToBuffer(str_repeat('A', 1024));

        $this->assertSame(1024, strlen($this->connection->getBuffer()));
        $this->assertFalse($this->connection->isClosed());
    }

    #[Test]
    public function buffer_exceeding_limit_closes_connection(): void
    {
        $this->connection->appendToBuffer(str_repeat('A', 1025));

        $this->assertTrue($this->connection->isClosed());
    }

    #[Test]
    public function buffer_gradually_exceeds_limit(): void
    {
        $this->connection->appendToBuffer(str_repeat('A', 512));
        $this->assertFalse($this->connection->isClosed());

        $this->connection->appendToBuffer(str_repeat('B', 513));

        $this->assertTrue($this->connection->isClosed());
    }

    #[Test]
    public function closed_connection_buffer_retains_data(): void
    {
        $this->connection->appendToBuffer(str_repeat('X', 2048));

        $this->assertTrue($this->connection->isClosed());
        $this->assertSame(2048, strlen($this->connection->getBuffer()));
    }

    #[Test]
    public function default_max_buffer_size_accepts_normal_request(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 8080);

        $connection->appendToBuffer(str_repeat('A', 10485760));

        $this->assertFalse($connection->isClosed());
        fclose($socket);
    }

    #[Test]
    public function default_max_buffer_size_rejects_oversized_request(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 8080);

        $connection->appendToBuffer(str_repeat('A', 10485761));

        $this->assertTrue($connection->isClosed());
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
