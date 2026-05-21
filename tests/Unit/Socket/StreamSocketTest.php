<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocket;
use Duyler\HttpServer\Tests\Support\ErrorReportingScope;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Socket;

class StreamSocketTest extends TestCase
{
    use ErrorReportingScope;
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

    #[Test]
    public function is_not_valid_initially(): void
    {
        $this->assertFalse($this->socket->isValid());
    }

    #[Test]
    public function binds_to_address_and_port(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function throws_exception_when_binding_to_used_port(): void
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

    #[Test]
    public function listens_after_bind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function throws_exception_when_listening_without_bind(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be bound before listening');

        $this->socket->listen();
    }

    #[Test]
    public function throws_exception_when_accepting_without_listening(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be listening before accepting connections');

        $this->socket->accept();
    }

    #[Test]
    public function sets_blocking_mode(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $this->socket->setBlocking(true);
        $this->socket->setBlocking(false);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function throws_exception_when_setting_blocking_on_invalid_socket(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket is not valid');

        $this->socket->setBlocking(true);
    }

    #[Test]
    public function closes_socket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->close();

        $this->assertFalse($this->socket->isValid());
    }

    #[Test]
    public function returns_null_resource_when_not_bound(): void
    {
        $resource = $this->socket->getInternalResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function returns_resource_after_bind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $resource = $this->socket->getInternalResource();

        $this->assertTrue(is_resource($resource) || $resource instanceof Socket);
    }

    #[Test]
    public function accepts_returns_false_in_non_blocking_mode_with_no_connections(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();
        $this->socket->setBlocking(false);

        $client = $this->socket->accept();

        $this->assertFalse($client);
    }

    #[Test]
    public function get_peer_name_returns_false_on_invalid_socket(): void
    {
        $result = $this->socket->getPeerName();

        $this->assertFalse($result);
    }

    #[Test]
    public function get_peer_name_returns_false_on_unconnected_socket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $previousHandler = set_error_handler(static fn(): bool => true);
        $result = $this->socket->getPeerName();
        restore_error_handler();

        $this->assertFalse($result);
    }

    #[Test]
    public function get_peer_name_returns_peer_info_on_connected_socket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();
        $this->socket->setBlocking(false);

        $port = $this->getSocketPort($this->socket);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($client);
        $this->withSuppressedErrors(fn() => socket_connect($client, '127.0.0.1', $port));

        usleep(10000);

        $accepted = $this->socket->accept();
        $this->assertNotFalse($accepted);

        $peerName = $accepted->getPeerName();

        $this->assertIsArray($peerName);
        $this->assertArrayHasKey('ip', $peerName);
        $this->assertArrayHasKey('port', $peerName);
        $this->assertSame('127.0.0.1', $peerName['ip']);
        $this->assertIsInt($peerName['port']);

        $accepted->close();
        socket_close($client);
    }

    #[Test]
    public function export_stream_returns_false_on_invalid_socket(): void
    {
        $result = $this->socket->exportStream();

        $this->assertFalse($result);
    }

    #[Test]
    public function export_stream_returns_resource_on_valid_socket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();
        $this->socket->setBlocking(false);

        $port = $this->getSocketPort($this->socket);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($client);
        $this->withSuppressedErrors(fn() => socket_connect($client, '127.0.0.1', $port));

        usleep(10000);

        $accepted = $this->socket->accept();
        $this->assertNotFalse($accepted);

        $stream = $accepted->exportStream();

        $this->assertIsResource($stream);

        $accepted->close();
        socket_close($client);
    }
}
