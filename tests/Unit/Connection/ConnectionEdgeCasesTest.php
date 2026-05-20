<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ConnectionEdgeCasesTest extends TestCase
{
    private SocketResourceInterface $socketResource;

    /** @var resource */
    private mixed $socket;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($this->socket);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    #[Test]
    public function append_to_buffer_closes_connection_on_overflow(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345, 10);

        $connection->appendToBuffer(str_repeat('x', 20));

        $this->assertTrue($connection->isClosed());
    }

    #[Test]
    public function consume_buffer_handles_partial_consumption(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->appendToBuffer('HelloWorld');
        $connection->consumeBuffer(5);

        $this->assertSame('World', $connection->getBuffer());
    }

    #[Test]
    public function consume_buffer_across_multiple_chunks(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->appendToBuffer('AAA');
        $connection->appendToBuffer('BBB');
        $connection->appendToBuffer('CCC');

        $connection->consumeBuffer(5);

        $this->assertSame('BCCC', $connection->getBuffer());
    }

    #[Test]
    public function consume_buffer_partial_chunk(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->appendToBuffer('HelloWorld');

        $connection->consumeBuffer(3);

        $this->assertSame('loWorld', $connection->getBuffer());
    }

    #[Test]
    public function consume_buffer_clears_request_cache(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->setCachedHeaders(['Content-Type' => ['text/html']]);
        $connection->setExpectedContentLength(100);
        $connection->startRequestTimer();

        $connection->appendToBuffer('Some data');
        $connection->consumeBuffer(4);

        $this->assertNull($connection->getCachedHeaders());
        $this->assertNull($connection->getExpectedContentLength());
        $this->assertNull($connection->getRequestStartTime());
    }

    #[Test]
    public function is_request_timed_out_returns_false_when_no_timer_started(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $this->assertFalse($connection->isRequestTimedOut(1));
    }

    #[Test]
    public function start_request_timer_only_sets_once(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->startRequestTimer();
        $firstTime = $connection->getRequestStartTime();

        usleep(1000);

        $connection->startRequestTimer();
        $secondTime = $connection->getRequestStartTime();

        $this->assertSame($firstTime, $secondTime);
    }

    #[Test]
    public function write_returns_false_when_invalid(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);
        $connection->close();

        $result = $connection->write('data');
        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_when_invalid(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);
        $connection->close();

        $result = $connection->read(1024);
        $this->assertFalse($result);
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);

        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $connection->close();
        $connection->close();

        $this->assertTrue($connection->isClosed());
        $this->assertFalse($connection->isValid());
    }

    #[Test]
    public function is_valid_returns_false_when_socket_invalid(): void
    {
        $mockSocket = $this->createStub(SocketResourceInterface::class);
        $mockSocket->method('isValid')->willReturn(false);

        $connection = new Connection($mockSocket, '127.0.0.1', 12345);

        $this->assertFalse($connection->isValid());
    }

    #[Test]
    public function clear_buffer_resets_state(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->appendToBuffer('Some data');
        $connection->setCachedHeaders(['X-Custom' => ['value']]);
        $connection->setExpectedContentLength(42);
        $connection->startRequestTimer();

        $connection->clearBuffer();

        $this->assertSame('', $connection->getBuffer());
        $this->assertNull($connection->getCachedHeaders());
        $this->assertNull($connection->getExpectedContentLength());
        $this->assertNull($connection->getRequestStartTime());
    }

    #[Test]
    public function get_buffer_concats_chunks(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->appendToBuffer('Hello');
        $connection->appendToBuffer(' ');
        $connection->appendToBuffer('World');

        $this->assertSame('Hello World', $connection->getBuffer());
    }

    #[Test]
    public function increment_request_count_works(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $this->assertSame(0, $connection->getRequestCount());

        $connection->incrementRequestCount();
        $this->assertSame(1, $connection->getRequestCount());

        $connection->incrementRequestCount();
        $this->assertSame(2, $connection->getRequestCount());
    }

    #[Test]
    public function is_timed_out_returns_true_after_timeout(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $reflection = new ReflectionProperty(Connection::class, 'lastActivityTime');
        $reflection->setValue($connection, microtime(true) - 100);

        $this->assertTrue($connection->isTimedOut(50));
    }

    #[Test]
    public function is_timed_out_returns_false_before_timeout(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $this->assertFalse($connection->isTimedOut(3600));
    }

    #[Test]
    public function update_activity_refreshes_last_activity_time(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $reflection = new ReflectionProperty(Connection::class, 'lastActivityTime');
        $reflection->setValue($connection, microtime(true) - 100);

        $this->assertTrue($connection->isTimedOut(50));

        $connection->updateActivity();

        $this->assertFalse($connection->isTimedOut(50));
    }

    #[Test]
    public function set_keep_alive_changes_state(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $this->assertFalse($connection->isKeepAlive());

        $connection->setKeepAlive(true);
        $this->assertTrue($connection->isKeepAlive());

        $connection->setKeepAlive(false);
        $this->assertFalse($connection->isKeepAlive());
    }

    #[Test]
    public function is_request_timed_out_returns_true_when_expired(): void
    {
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);

        $connection->startRequestTimer();

        $reflection = new ReflectionProperty(Connection::class, 'requestStartTime');
        $reflection->setValue($connection, microtime(true) - 100);

        $this->assertTrue($connection->isRequestTimedOut(50));
    }
}
