<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Socket\SocketNotificationPair;
use Error;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;

class SocketNotificationPairTest extends TestCase
{
    private SocketNotificationPair $pair;

    protected function setUp(): void
    {
        $this->pair = new SocketNotificationPair();
    }

    protected function tearDown(): void
    {
        $this->pair->close();
    }

    #[Test]
    public function is_disabled_by_default(): void
    {
        $this->assertFalse($this->pair->isEnabled());
    }

    #[Test]
    public function read_socket_is_null_by_default(): void
    {
        $this->assertNull($this->pair->getReadSocket());
    }

    #[Test]
    public function write_socket_is_null_by_default(): void
    {
        $this->assertNull($this->pair->getWriteSocket());
    }

    #[Test]
    public function create_pair_enables_sockets(): void
    {
        $this->pair->createPair();

        $this->assertTrue($this->pair->isEnabled());
        $this->assertInstanceOf(Socket::class, $this->pair->getReadSocket());
        $this->assertInstanceOf(Socket::class, $this->pair->getWriteSocket());
    }

    #[Test]
    public function create_pair_sets_nonblocking_mode(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();

        $this->assertInstanceOf(Socket::class, $readSocket);

        $data = socket_read($readSocket, 1, PHP_BINARY_READ);
        $this->assertFalse($data);
    }

    #[Test]
    public function notify_writes_byte_to_socket(): void
    {
        $this->pair->createPair();

        $this->pair->notify();

        $readSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);

        $data = socket_read($readSocket, 1, PHP_BINARY_READ);
        $this->assertSame('x', $data);
    }

    #[Test]
    public function notify_does_nothing_when_disabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $pair = new SocketNotificationPair($logger);
        $pair->notify();

        $this->assertFalse($pair->isEnabled());
        $this->assertNull($pair->getReadSocket());
    }

    #[Test]
    public function close_resets_sockets(): void
    {
        $this->pair->createPair();

        $this->assertTrue($this->pair->isEnabled());

        $this->pair->close();

        $this->assertFalse($this->pair->isEnabled());
        $this->assertNull($this->pair->getReadSocket());
        $this->assertNull($this->pair->getWriteSocket());
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $this->pair->close();
        $this->pair->close();
        $this->pair->close();

        $this->assertFalse($this->pair->isEnabled());
    }

    #[Test]
    public function recreate_pair_after_close(): void
    {
        $this->pair->createPair();
        $firstReadSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $firstReadSocket);

        $this->pair->close();

        $this->pair->createPair();
        $secondReadSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $secondReadSocket);

        $this->assertNotSame($firstReadSocket, $secondReadSocket);
        $this->assertTrue($this->pair->isEnabled());
    }

    #[Test]
    public function create_pair_closes_existing_pair_first(): void
    {
        $this->pair->createPair();
        $firstRead = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $firstRead);
        $firstWrite = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $firstWrite);

        socket_write($firstWrite, 'old', 3);
        $this->assertSame('old', socket_read($firstRead, 3, PHP_BINARY_READ));

        $this->pair->createPair();
        $secondRead = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $secondRead);

        $this->assertNotSame($firstRead, $secondRead);

        try {
            socket_read($firstRead, 1, PHP_BINARY_READ);
            $this->fail('Expected Error or false from closed socket');
        } catch (Error) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function notify_logs_warning_on_write_failure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->willReturnCallback(static function (string $message): void {
            $suffix = 'Failed to write notification byte: ';
            assert(str_starts_with($message, $suffix));
            assert(strlen($message) > strlen($suffix));
        });

        $pair = new SocketNotificationPair($logger);
        $pair->createPair();

        $writeSocket = $pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $writeSocket);
        socket_close($writeSocket);

        $pair->notify();

        $pair->close();
    }

    #[Test]
    public function multiple_notifications_are_buffered(): void
    {
        $this->pair->createPair();

        $this->pair->notify();
        $this->pair->notify();
        $this->pair->notify();

        $readSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);

        $data = socket_read($readSocket, 4096, PHP_BINARY_READ);
        $this->assertSame('xxx', $data);
    }

    #[Test]
    public function close_then_notify_does_nothing(): void
    {
        $this->pair->createPair();
        $this->pair->close();

        $this->pair->notify();

        $this->assertFalse($this->pair->isEnabled());
    }

    #[Test]
    public function constructor_accepts_logger(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $pair = new SocketNotificationPair($logger);

        $pair->createPair();
        $this->assertTrue($pair->isEnabled());

        $pair->close();
    }

    #[Test]
    public function write_socket_is_nonblocking(): void
    {
        $this->pair->createPair();

        $writeSocket = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $writeSocket);

        $data = socket_read($writeSocket, 1, PHP_BINARY_READ);
        $this->assertFalse($data);
    }

    #[Test]
    public function close_handles_externally_closed_read_socket(): void
    {
        $pair = new SocketNotificationPair();
        $pair->createPair();

        $readSocket = $pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);
        socket_close($readSocket);

        $writeSocket = $pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $writeSocket);
        socket_close($writeSocket);

        $pair->close();

        $this->assertFalse($pair->isEnabled());
        $this->assertNull($pair->getReadSocket());
        $this->assertNull($pair->getWriteSocket());
    }
}
