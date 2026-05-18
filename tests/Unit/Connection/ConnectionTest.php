<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Override;
use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{
    /** @var resource */
    private mixed $socket;
    private Connection $connection;
    private StreamSocketResource $socketResource;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->socketResource = new StreamSocketResource($this->socket);
        $this->connection = new Connection($this->socketResource, '127.0.0.1', 12345);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function testReturnsSocketResource(): void
    {
        $this->assertSame($this->socketResource, $this->connection->getSocket());
    }

    public function testReturnsRemoteAddress(): void
    {
        $this->assertSame('127.0.0.1', $this->connection->getRemoteAddress());
    }

    public function testReturnsRemotePort(): void
    {
        $this->assertSame(12345, $this->connection->getRemotePort());
    }

    public function testBufferIsEmptyInitially(): void
    {
        $this->assertSame('', $this->connection->getBuffer());
    }

    public function testAppendsDataToBuffer(): void
    {
        $this->connection->appendToBuffer('Hello');
        $this->assertSame('Hello', $this->connection->getBuffer());

        $this->connection->appendToBuffer(' World');
        $this->assertSame('Hello World', $this->connection->getBuffer());
    }

    public function testClearsBuffer(): void
    {
        $this->connection->appendToBuffer('test data');
        $this->connection->clearBuffer();

        $this->assertSame('', $this->connection->getBuffer());
    }

    public function testTracksRequestCount(): void
    {
        $this->assertSame(0, $this->connection->getRequestCount());

        $this->connection->incrementRequestCount();
        $this->assertSame(1, $this->connection->getRequestCount());

        $this->connection->incrementRequestCount();
        $this->assertSame(2, $this->connection->getRequestCount());
    }

    public function testUpdatesLastActivityTime(): void
    {
        $initialTime = $this->connection->getLastActivityTime();

        usleep(10000);
        $this->connection->updateActivity();

        $this->assertGreaterThan($initialTime, $this->connection->getLastActivityTime());
    }

    public function testDetectsTimeout(): void
    {
        $this->assertFalse($this->connection->isTimedOut(1));

        sleep(2);

        $this->assertTrue($this->connection->isTimedOut(1));
    }

    public function testManagesKeepAliveFlag(): void
    {
        $this->assertFalse($this->connection->isKeepAlive());

        $this->connection->setKeepAlive(true);
        $this->assertTrue($this->connection->isKeepAlive());

        $this->connection->setKeepAlive(false);
        $this->assertFalse($this->connection->isKeepAlive());
    }

    public function testWritesData(): void
    {
        $written = $this->connection->write('test data');

        $this->assertIsInt($written);
        $this->assertGreaterThan(0, $written);
    }

    public function testReadsData(): void
    {
        fwrite($this->socket, 'test content');
        rewind($this->socket);

        $data = $this->connection->read(4);

        $this->assertSame('test', $data);
    }

    public function testClosesConnection(): void
    {
        $this->connection->close();

        $this->assertFalse(is_resource($this->socket));
    }
}
