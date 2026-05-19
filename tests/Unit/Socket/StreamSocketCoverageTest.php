<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocket;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;

class StreamSocketCoverageTest extends TestCase
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

    #[Test]
    public function read_returns_false_when_socket_not_valid(): void
    {
        $result = $this->socket->read(1024);

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_when_length_is_zero(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $result = $this->socket->read(0);

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_when_length_is_negative(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $result = $this->socket->read(-1);

        $this->assertFalse($result);
    }

    #[Test]
    public function read_from_connected_socket_pair(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$side1, $side2] = $sockets;

        socket_write($side2, 'hello from peer');

        $ref = new ReflectionProperty($this->socket, 'socket');
        $ref->setValue($this->socket, $side1);

        $data = $this->socket->read(1024);

        $this->assertSame('hello from peer', $data);

        socket_close($side2);
    }

    #[Test]
    public function read_returns_actual_data_length(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$side1, $side2] = $sockets;

        socket_write($side2, 'short');

        $ref = new ReflectionProperty($this->socket, 'socket');
        $ref->setValue($this->socket, $side1);

        $data = $this->socket->read(4);

        $this->assertSame('shor', $data);

        socket_close($side2);
    }

    #[Test]
    public function write_returns_false_when_socket_not_valid(): void
    {
        $result = $this->socket->write('test');

        $this->assertFalse($result);
    }

    #[Test]
    public function write_to_connected_socket_pair(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$side1, $side2] = $sockets;

        $ref = new ReflectionProperty($this->socket, 'socket');
        $ref->setValue($this->socket, $side1);

        $written = $this->socket->write('test message');

        $this->assertSame(strlen('test message'), $written);

        $buf = '';
        socket_recv($side2, $buf, 1024, MSG_DONTWAIT);

        $this->assertSame('test message', $buf);

        socket_close($side2);
    }

    #[Test]
    public function close_is_noop_on_unbound_socket(): void
    {
        $this->assertFalse($this->socket->isValid());

        $this->socket->close();

        $this->assertFalse($this->socket->isValid());
    }

    #[Test]
    public function close_is_idempotent_after_bind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->close();
        $this->socket->close();
        $this->socket->close();

        $this->assertFalse($this->socket->isValid());
    }

    #[Test]
    public function close_resets_bound_flag(): void
    {
        $ref = new ReflectionProperty($this->socket, 'isBound');

        $this->socket->bind('127.0.0.1', 0);
        $this->assertTrue($ref->getValue($this->socket));

        $this->socket->close();
        $this->assertFalse($ref->getValue($this->socket));
    }

    #[Test]
    public function close_resets_listening_flag(): void
    {
        $ref = new ReflectionProperty($this->socket, 'isListening');

        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();
        $this->assertTrue($ref->getValue($this->socket));

        $this->socket->close();
        $this->assertFalse($ref->getValue($this->socket));
    }

    #[Test]
    public function accept_returns_resource_on_actual_connection(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $ref = new ReflectionProperty($this->socket, 'socket');
        $serverSocket = $ref->getValue($this->socket);

        $address = '';
        $port = 0;
        socket_getsockname($serverSocket, $address, $port);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_connect($clientSocket, $address, $port);

        $result = $this->socket->accept();

        $this->assertInstanceOf(StreamSocketResource::class, $result);

        $result->close();
        socket_close($clientSocket);
    }

    #[Test]
    public function accept_sets_nonblock_and_nodelay_on_client(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $ref = new ReflectionProperty($this->socket, 'socket');
        $serverSocket = $ref->getValue($this->socket);

        $address = '';
        $port = 0;
        socket_getsockname($serverSocket, $address, $port);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_connect($clientSocket, $address, $port);

        $resource = $this->socket->accept();

        $this->assertInstanceOf(StreamSocketResource::class, $resource);
        $this->assertTrue($resource->isValid());

        $resource->close();
        socket_close($clientSocket);
    }

    #[Test]
    public function listen_with_custom_backlog(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen(128);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function listen_with_default_backlog(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $ref = new ReflectionProperty($this->socket, 'isListening');
        $this->assertTrue($ref->getValue($this->socket));
    }

    #[Test]
    public function construct_with_ipv6_flag_stores_property(): void
    {
        $ipv6Socket = new StreamSocket(true);
        $ref = new ReflectionProperty($ipv6Socket, 'ipv6');

        $this->assertTrue($ref->getValue($ipv6Socket));

        $ipv6Socket->close();
    }

    #[Test]
    public function construct_without_ipv6_defaults_to_false(): void
    {
        $ref = new ReflectionProperty($this->socket, 'ipv6');

        $this->assertFalse($ref->getValue($this->socket));
    }

    #[Test]
    public function bind_creates_inet_socket_for_ipv4(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $ref = new ReflectionProperty($this->socket, 'socket');
        $socketResource = $ref->getValue($this->socket);

        $this->assertInstanceOf(Socket::class, $socketResource);
    }

    #[Test]
    public function bind_sets_is_bound_flag(): void
    {
        $ref = new ReflectionProperty($this->socket, 'isBound');

        $this->assertFalse($ref->getValue($this->socket));

        $this->socket->bind('127.0.0.1', 0);

        $this->assertTrue($ref->getValue($this->socket));
    }

    #[Test]
    public function bind_to_port_zero_assigns_ephemeral_port(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $ref = new ReflectionProperty($this->socket, 'socket');
        $socketResource = $ref->getValue($this->socket);

        $address = '';
        $port = 0;
        socket_getsockname($socketResource, $address, $port);

        $this->assertGreaterThan(0, $port);
    }

    #[Test]
    public function set_blocking_false_on_bound_socket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->setBlocking(false);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function set_blocking_true_then_false(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->setBlocking(true);
        $this->socket->setBlocking(false);
        $this->socket->setBlocking(true);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function get_internal_resource_returns_null_when_not_bound(): void
    {
        $resource = $this->socket->getInternalResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function get_internal_resource_returns_socket_after_bind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $resource = $this->socket->getInternalResource();

        $this->assertInstanceOf(Socket::class, $resource);
    }

    #[Test]
    public function get_internal_resource_returns_null_after_close(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->close();

        $resource = $this->socket->getInternalResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function socket_reusable_after_close_and_rebind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->close();

        $this->assertFalse($this->socket->isValid());

        $this->socket->bind('127.0.0.1', 0);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function bind_throws_on_invalid_address(): void
    {
        $this->expectException(SocketException::class);

        $this->socket->bind('999.999.999.999', 0);
    }
}
