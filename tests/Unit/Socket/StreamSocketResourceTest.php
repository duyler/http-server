<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocketResource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;

class StreamSocketResourceTest extends TestCase
{
    #[Test]
    public function creates_from_socket_object(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $resource = new StreamSocketResource($socket);

        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    #[Test]
    public function throws_on_invalid_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid socket resource or Socket object');

        new StreamSocketResource('invalid');
    }

    #[Test]
    public function throws_on_null_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamSocketResource(null);
    }

    #[Test]
    public function is_valid_returns_false_after_close(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $this->assertTrue($resource->isValid());

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    #[Test]
    public function set_blocking_on_socket_object(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->setBlocking(false);
        $this->assertTrue($resource->isValid());

        $resource->setBlocking(true);
        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    #[Test]
    public function throws_on_set_blocking_invalid_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot set blocking mode on invalid socket');

        $resource->setBlocking(false);
    }

    #[Test]
    public function read_returns_false_on_invalid_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $result = $resource->read(1024);

        $this->assertFalse($result);
    }

    #[Test]
    public function write_returns_false_on_invalid_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $result = $resource->write('test');

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_on_zero_length(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $result = $resource->read(0);

        $this->assertFalse($result);

        $resource->close();
    }

    #[Test]
    public function get_internal_resource_returns_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $internal = $resource->getInternalResource();

        $this->assertSame($socket, $internal);

        $resource->close();
    }

    #[Test]
    public function close_is_idempotent(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();
        $resource->close();
        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    #[Test]
    public function close_with_custom_logger(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $logger = $this->createStub(LoggerInterface::class);

        $resource = new StreamSocketResource($socket, $logger);

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    #[Test]
    public function creates_from_stream_resource(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);

        $resource = new StreamSocketResource($stream);

        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    #[Test]
    public function is_valid_returns_false_after_stream_close(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $this->assertTrue($resource->isValid());

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    #[Test]
    public function get_internal_resource_returns_null_after_close(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $this->assertNull($resource->getInternalResource());
    }

    #[Test]
    public function set_blocking_on_stream_resource(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $resource->setBlocking(false);
        $this->assertTrue($resource->isValid());

        $resource->setBlocking(true);
        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    #[Test]
    public function write_to_stream_resource(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $written = $resource->write('test data');

        $this->assertGreaterThan(0, $written);

        $resource->close();
    }

    #[Test]
    public function read_from_stream_resource(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'test data');
        rewind($stream);

        $resource = new StreamSocketResource($stream);

        $data = $resource->read(4);

        $this->assertSame('test', $data);

        $resource->close();
    }
    #[Test]
    public function read_returns_false_on_negative_length(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $result = $resource->read(-1);

        $this->assertFalse($result);

        $resource->close();
    }
    #[Test]
    public function read_from_socket_object(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        socket_write($server, 'test data');

        $resource = new StreamSocketResource($client);

        $data = $resource->read(4);

        $this->assertSame('test', $data);

        $resource->close();
        socket_close($server);
    }
    #[Test]
    public function write_to_socket_object(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        $resource = new StreamSocketResource($client);

        $written = $resource->write('test data');

        $this->assertGreaterThan(0, $written);

        $resource->close();
        socket_close($server);
    }

    #[Test]
    public function get_peer_name_returns_false_on_closed_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);
        $resource->close();

        $result = $resource->getPeerName();

        $this->assertFalse($result);
    }

    #[Test]
    public function get_peer_name_returns_false_on_unconnected_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $previousHandler = set_error_handler(static fn(): bool => true);
        $result = $resource->getPeerName();
        restore_error_handler();

        $this->assertFalse($result);

        $resource->close();
    }

    #[Test]
    public function get_peer_name_returns_peer_info_on_connected_socket(): void
    {
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($server, '127.0.0.1', 0);
        socket_listen($server, 1);
        socket_getsockname($server, $address, $port);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($client);
        socket_connect($client, '127.0.0.1', $port);

        usleep(10000);
        $accepted = socket_accept($server);

        socket_getpeername($accepted, $expectedIp, $expectedPort);

        $resource = new StreamSocketResource($accepted);
        $result = $resource->getPeerName();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ip', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertSame($expectedIp, $result['ip']);
        $this->assertIsInt($result['port']);
        $this->assertSame($expectedPort, $result['port']);

        $resource->close();
        socket_close($client);
        socket_close($server);
    }

    #[Test]
    public function get_peer_name_returns_false_on_closed_stream(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);
        $resource->close();

        $result = $resource->getPeerName();

        $this->assertFalse($result);
    }

    #[Test]
    public function get_peer_name_returns_false_on_non_socket_stream(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $result = $resource->getPeerName();

        $this->assertFalse($result);

        $resource->close();
    }

    #[Test]
    public function get_peer_name_returns_address_on_socket_stream(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $colonPos = strrpos($address, ':');
        $port = (int) substr($address, $colonPos + 1);

        $client = stream_socket_client("tcp://127.0.0.1:$port");
        $accepted = stream_socket_accept($server);

        $clientAddress = stream_socket_get_name($client, false);
        $clientPort = (int) substr($clientAddress, strrpos($clientAddress, ':') + 1);

        $resource = new StreamSocketResource($accepted);
        $result = $resource->getPeerName();

        $this->assertIsArray($result);
        $this->assertSame('127.0.0.1', $result['ip']);
        $this->assertIsInt($result['port']);
        $this->assertSame($clientPort, $result['port']);

        $resource->close();
        fclose($client);
        fclose($server);
    }

    #[Test]
    public function get_peer_name_returns_false_on_client_stream_with_remote(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($server, false);
        $colonPos = strrpos($address, ':');
        $port = (int) substr($address, $colonPos + 1);

        $client = stream_socket_client("tcp://127.0.0.1:$port");

        $resource = new StreamSocketResource($client);
        $result = $resource->getPeerName();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ip', $result);
        $this->assertArrayHasKey('port', $result);

        $resource->close();
        fclose($server);
    }

    #[Test]
    public function export_stream_returns_false_on_closed_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);
        $resource->close();

        $result = $resource->exportStream();

        $this->assertFalse($result);
    }

    #[Test]
    public function export_stream_returns_stream_from_socket(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        socket_set_nonblock($client);

        $resource = new StreamSocketResource($client);
        $stream = $resource->exportStream();

        $this->assertIsResource($stream);

        $resource->close();
        socket_close($server);
    }

    #[Test]
    public function export_stream_returns_same_stream_resource_from_stream(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $result = $resource->exportStream();

        $this->assertIsResource($result);
        $this->assertSame($stream, $result);

        $resource->close();
    }

    #[Test]
    public function export_stream_returns_false_on_closed_stream(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);
        $resource->close();

        $result = $resource->exportStream();

        $this->assertFalse($result);
    }

    #[Test]
    public function export_stream_restores_nonblocking_after_export(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        socket_set_nonblock($client);

        $resource = new StreamSocketResource($client);
        $resource->exportStream();

        $data = socket_read($client, 1, PHP_BINARY_READ);
        $this->assertFalse($data);

        $this->assertTrue($resource->isValid());

        $resource->close();
        socket_close($server);
    }

    #[Test]
    public function export_stream_returns_false_when_set_block_fails(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        socket_close($socket);

        $result = $resource->exportStream();

        $this->assertFalse($result);
    }

    #[Test]
    public function export_stream_returns_resource_on_valid_socket(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        $resource = new StreamSocketResource($client);
        $stream = $resource->exportStream();

        $this->assertIsResource($stream);

        $resource->close();
        socket_close($server);
    }

}
