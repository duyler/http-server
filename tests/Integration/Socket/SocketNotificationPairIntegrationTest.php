<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Socket;

use Duyler\HttpServer\Socket\SocketNotificationPair;
use Error;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

#[CoversClass(SocketNotificationPair::class)]
class SocketNotificationPairIntegrationTest extends TestCase
{
    private SocketNotificationPair $pair;

    #[Override]
    protected function setUp(): void
    {
        $this->pair = new SocketNotificationPair();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->pair->close();
    }

    #[Test]
    public function create_pair_produces_real_unix_socket_pair(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $writeSocket = $this->pair->getWriteSocket();

        $this->assertInstanceOf(Socket::class, $readSocket);
        $this->assertInstanceOf(Socket::class, $writeSocket);

        $written = socket_write($writeSocket, 'integration-test-data');
        $this->assertNotFalse($written);
        $this->assertSame(strlen('integration-test-data'), $written);

        $read = socket_read($readSocket, 4096, PHP_BINARY_READ);
        $this->assertSame('integration-test-data', $read);
    }

    #[Test]
    public function notify_sends_real_byte_through_socket_pair(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);

        $this->pair->notify();

        $read = [$readSocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed);

        $data = socket_read($readSocket, 1, PHP_BINARY_READ);
        $this->assertSame('x', $data);
    }

    #[Test]
    public function multiple_notifications_are_readable_as_buffer(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);

        for ($i = 0; $i < 10; $i++) {
            $this->pair->notify();
        }

        $read = [$readSocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed);

        $data = socket_read($readSocket, 4096, PHP_BINARY_READ);
        $this->assertSame(10, strlen($data));
        $this->assertSame(str_repeat('x', 10), $data);
    }

    #[Test]
    public function close_releases_socket_resources(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $writeSocket = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);
        $this->assertInstanceOf(Socket::class, $writeSocket);

        $this->pair->close();

        $this->assertFalse($this->pair->isEnabled());
        $this->assertNull($this->pair->getReadSocket());
        $this->assertNull($this->pair->getWriteSocket());

        try {
            socket_read($readSocket, 1, PHP_BINARY_READ);
            $this->fail('Socket should be closed');
        } catch (Error) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function is_enabled_reflects_real_socket_state(): void
    {
        $this->assertFalse($this->pair->isEnabled());

        $this->pair->createPair();
        $this->assertTrue($this->pair->isEnabled());

        $this->pair->close();
        $this->assertFalse($this->pair->isEnabled());

        $this->pair->createPair();
        $this->assertTrue($this->pair->isEnabled());
    }

    #[Test]
    public function socket_pair_is_nonblocking(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);

        $data = socket_read($readSocket, 4096, PHP_BINARY_READ);
        $this->assertFalse($data);

        $writeSocket = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $writeSocket);

        socket_write($writeSocket, 'test');
        $data = socket_read($readSocket, 4, PHP_BINARY_READ);
        $this->assertSame('test', $data);
    }

    #[Test]
    public function recreate_pair_after_close_produces_new_sockets(): void
    {
        $this->pair->createPair();

        $firstRead = $this->pair->getReadSocket();
        $firstWrite = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $firstRead);
        $this->assertInstanceOf(Socket::class, $firstWrite);

        socket_write($firstWrite, 'first');
        $this->assertSame('first', socket_read($firstRead, 5, PHP_BINARY_READ));

        $this->pair->close();
        $this->pair->createPair();

        $secondRead = $this->pair->getReadSocket();
        $secondWrite = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $secondRead);
        $this->assertInstanceOf(Socket::class, $secondWrite);

        $this->assertNotSame($firstRead, $secondRead);
        $this->assertNotSame($firstWrite, $secondWrite);

        socket_write($secondWrite, 'second');
        $this->assertSame('second', socket_read($secondRead, 6, PHP_BINARY_READ));
    }

    #[Test]
    public function bidirectional_communication_through_pair(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $writeSocket = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);
        $this->assertInstanceOf(Socket::class, $writeSocket);

        socket_write($writeSocket, 'to-read-socket');
        $data = socket_read($readSocket, 4096, PHP_BINARY_READ);
        $this->assertSame('to-read-socket', $data);

        socket_write($readSocket, 'to-write-socket');
        $data = socket_read($writeSocket, 4096, PHP_BINARY_READ);
        $this->assertSame('to-write-socket', $data);
    }

    #[Test]
    public function select_detects_notification_readability(): void
    {
        $this->pair->createPair();

        $readSocket = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $readSocket);

        $read = [$readSocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 0);
        $this->assertSame(0, $changed);

        $this->pair->notify();

        $read = [$readSocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed);
    }

    #[Test]
    public function create_pair_closes_previous_pair_before_creating_new(): void
    {
        $this->pair->createPair();

        $oldRead = $this->pair->getReadSocket();
        $oldWrite = $this->pair->getWriteSocket();
        $this->assertInstanceOf(Socket::class, $oldRead);
        $this->assertInstanceOf(Socket::class, $oldWrite);

        socket_write($oldWrite, 'old-data');

        $this->pair->createPair();

        $newRead = $this->pair->getReadSocket();
        $this->assertInstanceOf(Socket::class, $newRead);
        $this->assertNotSame($oldRead, $newRead);

        try {
            socket_read($oldRead, 1, PHP_BINARY_READ);
            $this->fail('Old socket should be closed');
        } catch (Error) {
            $this->assertTrue(true);
        }
    }
}
