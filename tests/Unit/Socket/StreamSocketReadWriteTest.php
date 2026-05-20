<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Socket\StreamSocket;
use Duyler\HttpServer\Tests\Support\ErrorReportingScope;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Socket;

class StreamSocketReadWriteTest extends TestCase
{
    use ErrorReportingScope;
    private StreamSocket $server;
    private StreamSocket $client;

    #[Override]
    protected function setUp(): void
    {
        $this->server = new StreamSocket();
        $this->client = new StreamSocket();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->server->close();
        $this->client->close();
    }

    #[Test]
    public function read_returns_false_when_not_bound(): void
    {
        $result = $this->server->read(100);

        $this->assertFalse($result);
    }

    #[Test]
    public function write_returns_false_when_not_bound(): void
    {
        $result = $this->server->write('data');

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_for_zero_length(): void
    {
        $this->server->bind('127.0.0.1', 0);
        $this->server->listen();

        $result = $this->server->read(0);

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_for_negative_length(): void
    {
        $this->server->bind('127.0.0.1', 0);
        $this->server->listen();

        $result = $this->server->read(-1);

        $this->assertFalse($result);
    }

    #[Test]
    public function accept_returns_resource_on_connection(): void
    {
        $this->server->bind('127.0.0.1', 0);
        $this->server->listen();
        $this->server->setBlocking(false);

        $port = $this->getSocketPort($this->server);

        $this->client->bind('127.0.0.1', 0);
        $this->client->setBlocking(false);

        $this->withSuppressedErrors(fn() => socket_connect(
            $this->extractSocket($this->client),
            '127.0.0.1',
            $port,
        ));

        usleep(10000);

        $resource = $this->server->accept();

        $this->assertNotFalse($resource);
    }

    #[Test]
    public function read_and_write_through_connected_sockets(): void
    {
        $this->server->bind('127.0.0.1', 0);
        $this->server->listen();

        $port = $this->getSocketPort($this->server);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);

        socket_set_nonblock($clientSocket);
        $this->withSuppressedErrors(fn() => socket_connect($clientSocket, '127.0.0.1', $port));

        usleep(10000);

        $serverConn = $this->server->accept();
        $this->assertNotFalse($serverConn);

        $written = socket_write($clientSocket, 'hello world');
        $this->assertNotFalse($written);

        usleep(10000);

        $data = $serverConn->read(1024);

        $this->assertSame('hello world', $data);

        $serverConn->write('response');
        usleep(10000);

        $response = socket_read($clientSocket, 1024);
        $this->assertSame('response', $response);

        socket_close($clientSocket);
    }

    #[Test]
    public function close_does_nothing_when_not_bound(): void
    {
        $this->server->close();

        $this->assertFalse($this->server->isValid());
    }

    #[Test]
    public function write_on_connected_socket_returns_bytes_written(): void
    {
        $this->server->bind('127.0.0.1', 0);
        $this->server->listen();

        $port = $this->getSocketPort($this->server);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);

        $this->withSuppressedErrors(fn() => socket_connect($clientSocket, '127.0.0.1', $port));
        usleep(10000);

        $serverConn = $this->server->accept();
        $this->assertNotFalse($serverConn);

        $written = $serverConn->write('test data');

        $this->assertNotFalse($written);
        $this->assertGreaterThan(0, $written);

        socket_close($clientSocket);
    }

    private function getSocketPort(StreamSocket $socket): int
    {
        $reflection = new ReflectionClass($socket);
        $property = $reflection->getProperty('socket');
        $socketResource = $property->getValue($socket);

        $address = '';
        $port = 0;
        if ($socketResource instanceof Socket) {
            socket_getsockname($socketResource, $address, $port);
        }

        return $port;
    }

    private function extractSocket(StreamSocket $socket): Socket
    {
        $reflection = new ReflectionClass($socket);
        $property = $reflection->getProperty('socket');
        $socketResource = $property->getValue($socket);

        $this->assertInstanceOf(Socket::class, $socketResource);

        return $socketResource;
    }
}
