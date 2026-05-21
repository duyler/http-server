<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Socket;

use Duyler\HttpServer\Socket\StreamSocketResource;
use Error;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

#[CoversClass(StreamSocketResource::class)]
class SocketResourceIntegrationTest extends TestCase
{
    /** @var list<Socket|resource> */
    private array $cleanupResources = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->cleanupResources as $resource) {
            if ($resource instanceof Socket) {
                try {
                    socket_close($resource);
                } catch (Error) {
                }
            } elseif (is_resource($resource)) {
                fclose($resource);
            }
        }
        $this->cleanupResources = [];
    }

    private function registerCleanup(mixed $resource): void
    {
        $this->cleanupResources[] = $resource;
    }

    #[Test]
    public function get_peer_name_on_real_tcp_connection_returns_client_address(): void
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($serverSocket);
        $this->registerCleanup($serverSocket);

        socket_bind($serverSocket, '127.0.0.1', 0);
        socket_listen($serverSocket, 1);
        socket_getsockname($serverSocket, $serverAddr, $serverPort);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);
        $this->registerCleanup($clientSocket);

        socket_set_nonblock($clientSocket);
        @socket_connect($clientSocket, '127.0.0.1', $serverPort);

        usleep(50000);

        $acceptedSocket = socket_accept($serverSocket);
        $this->assertNotFalse($acceptedSocket);
        $this->registerCleanup($acceptedSocket);

        $resource = new StreamSocketResource($acceptedSocket);
        $peerInfo = $resource->getPeerName();

        $this->assertIsArray($peerInfo);
        $this->assertArrayHasKey('ip', $peerInfo);
        $this->assertArrayHasKey('port', $peerInfo);
        $this->assertSame('127.0.0.1', $peerInfo['ip']);
        $this->assertIsInt($peerInfo['port']);
        $this->assertGreaterThan(0, $peerInfo['port']);

        $directIp = '';
        $directPort = 0;
        socket_getpeername($acceptedSocket, $directIp, $directPort);
        $this->assertSame($directIp, $peerInfo['ip']);
        $this->assertSame($directPort, $peerInfo['port']);

        $resource->close();
    }

    #[Test]
    public function get_peer_name_on_stream_based_tcp_connection(): void
    {
        $serverStream = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($serverStream);
        $this->registerCleanup($serverStream);

        $address = stream_socket_get_name($serverStream, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $clientStream = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 2);
        $this->assertNotFalse($clientStream);
        $this->registerCleanup($clientStream);

        $acceptedStream = stream_socket_accept($serverStream, 1);
        $this->assertNotFalse($acceptedStream);
        $this->registerCleanup($acceptedStream);

        $resource = new StreamSocketResource($acceptedStream);
        $peerInfo = $resource->getPeerName();

        $this->assertIsArray($peerInfo);
        $this->assertSame('127.0.0.1', $peerInfo['ip']);
        $this->assertIsInt($peerInfo['port']);
        $this->assertGreaterThan(0, $peerInfo['port']);

        $clientAddress = stream_socket_get_name($clientStream, false);
        $clientPort = (int) substr($clientAddress, strrpos($clientAddress, ':') + 1);
        $this->assertSame($clientPort, $peerInfo['port']);

        $resource->close();
    }

    #[Test]
    public function export_stream_on_real_socket_pair_enables_stream_io(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;
        $this->registerCleanup($server);
        $this->registerCleanup($client);

        socket_set_nonblock($client);

        $resource = new StreamSocketResource($client);
        $stream = $resource->exportStream();

        $this->assertIsResource($stream);

        fwrite($stream, "hello from stream\n");
        fflush($stream);

        $data = socket_read($server, 4096, PHP_BINARY_READ);
        $this->assertSame("hello from stream\n", $data);

        socket_write($server, "response from socket\n");

        stream_set_blocking($stream, true);
        $response = fgets($stream);
        $this->assertSame("response from socket\n", $response);

        $resource->close();
    }

    #[Test]
    public function export_stream_on_real_tcp_connection(): void
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($serverSocket);
        $this->registerCleanup($serverSocket);

        socket_bind($serverSocket, '127.0.0.1', 0);
        socket_listen($serverSocket, 1);
        socket_getsockname($serverSocket, $serverAddr, $serverPort);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);
        $this->registerCleanup($clientSocket);

        socket_set_nonblock($clientSocket);
        @socket_connect($clientSocket, '127.0.0.1', $serverPort);

        usleep(50000);

        $acceptedSocket = socket_accept($serverSocket);
        $this->assertNotFalse($acceptedSocket);
        $this->registerCleanup($acceptedSocket);

        $resource = new StreamSocketResource($acceptedSocket);
        $stream = $resource->exportStream();

        $this->assertIsResource($stream);

        fwrite($stream, "HTTP request data\r\n\r\n");
        fflush($stream);

        $data = fread($stream, 4096);
        $this->assertIsString($data);

        $resource->close();
    }

    #[Test]
    public function get_peer_name_and_export_stream_on_same_connection(): void
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($serverSocket);
        $this->registerCleanup($serverSocket);

        socket_bind($serverSocket, '127.0.0.1', 0);
        socket_listen($serverSocket, 1);
        socket_getsockname($serverSocket, $addr, $port);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);
        $this->registerCleanup($clientSocket);

        socket_set_nonblock($clientSocket);
        @socket_connect($clientSocket, '127.0.0.1', $port);

        usleep(50000);

        $acceptedSocket = socket_accept($serverSocket);
        $this->assertNotFalse($acceptedSocket);
        $this->registerCleanup($acceptedSocket);

        $resource = new StreamSocketResource($acceptedSocket);

        $peerInfo = $resource->getPeerName();
        $this->assertIsArray($peerInfo);
        $this->assertSame('127.0.0.1', $peerInfo['ip']);

        $stream = $resource->exportStream();
        $this->assertIsResource($stream);

        socket_write($clientSocket, 'test-data');
        usleep(10000);

        stream_set_blocking($stream, true);
        $data = fread($stream, 4096);
        $this->assertSame('test-data', $data);

        $resource->close();
    }

    #[Test]
    public function configure_client_creates_resource_with_real_socket(): void
    {
        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($server);
        $this->registerCleanup($server);

        socket_bind($server, '127.0.0.1', 0);
        socket_listen($server, 1);
        socket_getsockname($server, $addr, $port);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($client);
        $this->registerCleanup($client);

        @socket_connect($client, '127.0.0.1', $port);
        usleep(50000);

        $accepted = socket_accept($server);
        $this->assertNotFalse($accepted);
        $this->registerCleanup($accepted);

        $resource = StreamSocketResource::configureClient($accepted);

        $this->assertTrue($resource->isValid());

        socket_write($client, 'configured-client-data');
        $data = $resource->read(4096);
        $this->assertSame('configured-client-data', $data);

        $written = $resource->write('response');
        $this->assertGreaterThan(0, $written);

        $response = socket_read($client, 4096, PHP_BINARY_READ);
        $this->assertSame('response', $response);

        $resource->close();
    }

    #[Test]
    public function get_peer_name_returns_false_on_unconnected_tcp_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->registerCleanup($socket);

        $resource = new StreamSocketResource($socket);

        set_error_handler(static fn(): bool => true);
        $result = $resource->getPeerName();
        restore_error_handler();

        $this->assertFalse($result);

        $resource->close();
    }

    #[Test]
    public function export_stream_on_stream_server_client_pair(): void
    {
        $serverStream = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($serverStream);
        $this->registerCleanup($serverStream);

        $address = stream_socket_get_name($serverStream, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $clientStream = stream_socket_client("tcp://127.0.0.1:$port", timeout: 2);
        $this->assertNotFalse($clientStream);
        $this->registerCleanup($clientStream);

        $acceptedStream = stream_socket_accept($serverStream, 1);
        $this->assertNotFalse($acceptedStream);
        $this->registerCleanup($acceptedStream);

        $resource = new StreamSocketResource($acceptedStream);

        $stream = $resource->exportStream();
        $this->assertIsResource($stream);
        $this->assertSame($acceptedStream, $stream);

        fwrite($clientStream, "stream-data\n");
        usleep(10000);

        stream_set_blocking($acceptedStream, false);
        $data = fread($acceptedStream, 4096);
        $this->assertSame("stream-data\n", $data);

        $resource->close();
    }

    #[Test]
    public function select_detects_readable_data_on_socket_resource(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;
        $this->registerCleanup($server);
        $this->registerCleanup($client);

        socket_set_nonblock($server);
        socket_set_nonblock($client);

        $result = StreamSocketResource::select([$client], 0);
        $this->assertNull($result);

        socket_write($server, 'trigger-readability');

        $result = StreamSocketResource::select([$client], 1);
        $this->assertNotNull($result);
        $this->assertContains($client, $result);

        socket_close($server);
        socket_close($client);

        $idx = array_search($server, $this->cleanupResources, true);
        if (false !== $idx) {
            unset($this->cleanupResources[$idx]);
        }
        $idx = array_search($client, $this->cleanupResources, true);
        if (false !== $idx) {
            unset($this->cleanupResources[$idx]);
        }
    }
}
