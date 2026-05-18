<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocket;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Socket;

class StreamSocketTest extends TestCase
{
    private StreamSocket $socket;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = new StreamSocket();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->socket->close();
    }

    public function testIsNotValidInitially(): void
    {
        $this->assertFalse($this->socket->isValid());
    }

    public function testBindsToAddressAndPort(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $this->assertTrue($this->socket->isValid());
    }

    public function testThrowsExceptionWhenBindingToUsedPort(): void
    {
        $socket1 = new StreamSocket();
        $socket1->bind('127.0.0.1', 0);
        $socket1->listen();

        $port = $this->getSocketPort($socket1);

        $this->expectException(SocketException::class);

        $socket2 = new StreamSocket();
        try {
            $socket2->bind('127.0.0.1', $port);
        } finally {
            $socket1->close();
            $socket2->close();
        }
    }

    private function getSocketPort(StreamSocket $socket): int
    {
        $reflection = new ReflectionClass($socket);
        $property = $reflection->getProperty('socket');
        $socketResource = $property->getValue($socket);

        if ($socketResource instanceof Socket) {
            $address = '';
            $port = 0;
            socket_getsockname($socketResource, $address, $port);
            return $port;
        }

        if (is_resource($socketResource)) {
            $name = stream_socket_get_name($socketResource, false);
            $parts = explode(':', $name);
            return (int) end($parts);
        }

        return 0;
    }

    public function testListensAfterBind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $this->assertTrue($this->socket->isValid());
    }

    public function testThrowsExceptionWhenListeningWithoutBind(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be bound before listening');

        $this->socket->listen();
    }

    public function testThrowsExceptionWhenAcceptingWithoutListening(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be listening before accepting connections');

        $this->socket->accept();
    }

    public function testSetsBlockingMode(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $this->socket->setBlocking(true);
        $this->socket->setBlocking(false);

        $this->assertTrue($this->socket->isValid());
    }

    public function testThrowsExceptionWhenSettingBlockingOnInvalidSocket(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket is not valid');

        $this->socket->setBlocking(true);
    }

    public function testClosesSocket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->close();

        $this->assertFalse($this->socket->isValid());
    }

    public function testReturnsNullResourceWhenNotBound(): void
    {
        $resource = $this->socket->getInternalResource();

        $this->assertNull($resource);
    }

    public function testReturnsResourceAfterBind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $resource = $this->socket->getInternalResource();

        $this->assertTrue(is_resource($resource) || $resource instanceof Socket);
    }

    public function testAcceptsReturnsFalseInNonBlockingModeWithNoConnections(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();
        $this->socket->setBlocking(false);

        $client = $this->socket->accept();

        $this->assertFalse($client);
    }
}
