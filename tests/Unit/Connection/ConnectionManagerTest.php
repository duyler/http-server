<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Connection\ConnectionManager;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Processor\RequestQueue;
use Duyler\HttpServer\Processor\ResponseSender;
use Duyler\HttpServer\Socket\SocketInterface;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConnectionManagerTest extends TestCase
{
    private ConnectionManager $manager;
    private ConnectionPool $pool;
    private ServerMetrics $metrics;

    #[Override]
    protected function setUp(): void
    {
        $this->pool = new ConnectionPool();
        $httpParser = new HttpParser(100);
        $psrFactory = new Psr17Factory();
        $tempFileManager = new \Duyler\HttpServer\Upload\TempFileManager();
        $requestParser = new \Duyler\HttpServer\Parser\RequestParser($httpParser, $psrFactory, $tempFileManager);
        $responseWriter = new \Duyler\HttpServer\Parser\ResponseWriter();
        $this->metrics = new ServerMetrics();
        $config = new \Duyler\HttpServer\Config\ServerConfig();

        $requestProcessor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $this->pool,
            $this->metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
        );

        $this->manager = new ConnectionManager(
            $this->pool,
            $httpParser,
            $requestProcessor,
            $this->metrics,
            $config,
            new NullLogger(),
        );

        $requestProcessor->setConnectionManager($this->manager);
    }

    #[Test]
    public function count_returns_zero_on_empty_pool(): void
    {
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function close_all_clears_pool(): void
    {
        $this->manager->closeAll();
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function logger_injected_via_constructor(): void
    {
        $logger = new NullLogger();
        $httpParser = new HttpParser(100);
        $psrFactory = new Psr17Factory();
        $tempFileManager = new \Duyler\HttpServer\Upload\TempFileManager();
        $requestParser = new \Duyler\HttpServer\Parser\RequestParser($httpParser, $psrFactory, $tempFileManager);
        $responseWriter = new \Duyler\HttpServer\Parser\ResponseWriter();
        $metrics = new ServerMetrics();
        $config = new \Duyler\HttpServer\Config\ServerConfig();
        $pool = new ConnectionPool();

        $requestProcessor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $pool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
        );

        $manager = new ConnectionManager(
            $pool,
            $httpParser,
            $requestProcessor,
            $metrics,
            $config,
            $logger,
        );

        $requestProcessor->setConnectionManager($manager);

        $this->assertInstanceOf(ConnectionManager::class, $manager);
    }

    #[Test]
    public function remove_timed_out_returns_zero_when_empty(): void
    {
        $result = $this->manager->removeTimedOut(30);
        $this->assertSame(0, $result);
    }

    #[Test]
    public function get_all_returns_empty_array_initially(): void
    {
        $this->assertSame([], $this->manager->getAll());
    }

    #[Test]
    public function accept_from_server_socket_uses_get_peer_name(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '192.168.1.100', 'port' => 54321]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $accepted = $this->manager->acceptFromServerSocket($socket, 10, false);

        $this->assertSame(1, $accepted);
        $this->assertSame(1, $this->pool->count());

        $connections = $this->manager->getAll();
        $this->assertSame('192.168.1.100', $connections[0]->getRemoteAddress());
        $this->assertSame(54321, $connections[0]->getRemotePort());
    }

    #[Test]
    public function accept_from_server_socket_fallback_when_get_peer_name_returns_false(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(false);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $accepted = $this->manager->acceptFromServerSocket($socket, 10, false);

        $this->assertSame(1, $accepted);
        $this->assertSame(1, $this->pool->count());

        $connections = $this->manager->getAll();
        $this->assertSame('0.0.0.0', $connections[0]->getRemoteAddress());
        $this->assertSame(0, $connections[0]->getRemotePort());
    }

    #[Test]
    public function accept_from_server_socket_returns_zero_when_no_connections(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);

        $socket->method('accept')->willReturn(false);

        $accepted = $this->manager->acceptFromServerSocket($socket, 10, false);

        $this->assertSame(0, $accepted);
        $this->assertSame(0, $this->pool->count());
    }

    #[Test]
    public function accept_from_server_socket_accepts_multiple_connections(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource1 */
        $clientResource1 = $this->createStub(SocketResourceInterface::class);
        /** @var SocketResourceInterface $clientResource2 */
        $clientResource2 = $this->createStub(SocketResourceInterface::class);

        $clientResource1->method('isValid')->willReturn(true);
        $clientResource1->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1111]);

        $clientResource2->method('isValid')->willReturn(true);
        $clientResource2->method('getPeerName')->willReturn(['ip' => '10.0.0.2', 'port' => 2222]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource1, $clientResource2, false);

        $accepted = $this->manager->acceptFromServerSocket($socket, 10, false);

        $this->assertSame(2, $accepted);
        $this->assertSame(2, $this->pool->count());
    }

    #[Test]
    public function accept_from_server_socket_respects_max_accepts(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1111]);

        $socket->method('accept')->willReturn($clientResource);

        $accepted = $this->manager->acceptFromServerSocket($socket, 3, false);

        $this->assertSame(3, $accepted);
        $this->assertSame(3, $this->pool->count());
    }

    #[Test]
    public function accept_from_server_socket_logs_in_debug_mode(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '127.0.0.1', 'port' => 8080]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            'New connection accepted',
            $this->callback(fn(array $context): bool => '127.0.0.1:8080' === $context['remote']
                && 1 === $context['total_connections']
                && 1 === $context['accepts_this_cycle']),
        );

        $httpParser = new HttpParser(100);
        $psrFactory = new Psr17Factory();
        $tempFileManager = new \Duyler\HttpServer\Upload\TempFileManager();
        $requestParser = new \Duyler\HttpServer\Parser\RequestParser($httpParser, $psrFactory, $tempFileManager);
        $responseWriter = new \Duyler\HttpServer\Parser\ResponseWriter();
        $config = new \Duyler\HttpServer\Config\ServerConfig();
        $pool = new ConnectionPool();
        $metrics = new ServerMetrics();

        $requestProcessor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $pool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
        );

        $manager = new ConnectionManager(
            $pool,
            $httpParser,
            $requestProcessor,
            $metrics,
            $config,
            $logger,
        );

        $requestProcessor->setConnectionManager($manager);

        $accepted = $manager->acceptFromServerSocket($socket, 10, true);

        $this->assertSame(1, $accepted);
    }

    #[Test]
    public function accept_from_server_socket_increments_metrics(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1234]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $this->manager->acceptFromServerSocket($socket, 10, false);

        $metricsData = $this->metrics->getMetrics();
        $this->assertSame(1, $metricsData['total_connections']);
    }

    #[Test]
    public function set_logger_updates_logger(): void
    {
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $this->manager->setLogger($logger);

        $this->assertInstanceOf(ConnectionManager::class, $this->manager);
    }

    #[Test]
    public function cleanup_timed_out_removes_connections(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1234]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $this->manager->acceptFromServerSocket($socket, 10, false);
        $this->assertSame(1, $this->pool->count());

        $removed = $this->manager->cleanupTimedOut(0);

        $this->assertSame(1, $removed);
        $this->assertSame(0, $this->pool->count());

        $metricsData = $this->metrics->getMetrics();
        $this->assertSame(1, $metricsData['timed_out_connections']);
    }

    #[Test]
    public function add_adds_connection_to_pool(): void
    {
        /** @var SocketResourceInterface $mockSocket */
        $mockSocket = $this->createStub(SocketResourceInterface::class);
        $mockSocket->method('isValid')->willReturn(true);

        $connection = new Connection($mockSocket, '127.0.0.1', 8080);
        $this->manager->add($connection);

        $this->assertSame(1, $this->manager->count());
        $this->assertSame($connection, $this->manager->getAll()[0]);
    }

    #[Test]
    public function remove_removes_connection_from_pool(): void
    {
        /** @var SocketResourceInterface $mockSocket */
        $mockSocket = $this->createStub(SocketResourceInterface::class);
        $mockSocket->method('isValid')->willReturn(true);

        $connection = new Connection($mockSocket, '127.0.0.1', 8080);
        $this->manager->add($connection);
        $this->assertSame(1, $this->manager->count());

        $this->manager->remove($connection);
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function find_by_socket_returns_matching_connection(): void
    {
        /** @var SocketResourceInterface $mockSocket */
        $mockSocket = $this->createStub(SocketResourceInterface::class);
        $mockSocket->method('isValid')->willReturn(true);

        $connection = new Connection($mockSocket, '192.168.1.1', 9000);
        $this->manager->add($connection);

        $found = $this->manager->findBySocket($mockSocket);
        $this->assertSame($connection, $found);
    }

    #[Test]
    public function find_by_socket_returns_null_when_not_found(): void
    {
        /** @var SocketResourceInterface $mockSocket */
        $mockSocket = $this->createStub(SocketResourceInterface::class);

        $found = $this->manager->findBySocket($mockSocket);
        $this->assertNull($found);
    }

    #[Test]
    public function close_connection_with_metrics_removes_from_pool(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1234]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $this->manager->acceptFromServerSocket($socket, 10, false);
        $this->assertSame(1, $this->pool->count());

        $connection = $this->manager->getAll()[0];
        $this->manager->closeConnectionWithMetrics($connection);

        $this->assertSame(0, $this->pool->count());
    }

    #[Test]
    public function close_connection_with_metrics_increments_metric(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1234]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $this->manager->acceptFromServerSocket($socket, 10, false);
        $connection = $this->manager->getAll()[0];

        $this->manager->closeConnectionWithMetrics($connection);

        $metricsData = $this->metrics->getMetrics();
        $this->assertSame(1, $metricsData['closed_connections']);
    }

    #[Test]
    public function close_connection_with_metrics_logs_in_debug_mode(): void
    {
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('debug')->with(
            'Closing connection',
            $this->callback(fn(array $context): bool => '10.0.0.5:5500' === $context['remote']
                && 0 === $context['active_connections']
                && 0 === $context['request_count']),
        );

        $config = new \Duyler\HttpServer\Config\ServerConfig(debugMode: true);
        $pool = new ConnectionPool();
        $httpParser = new HttpParser(100);
        $psrFactory = new Psr17Factory();
        $tempFileManager = new \Duyler\HttpServer\Upload\TempFileManager();
        $requestParser = new \Duyler\HttpServer\Parser\RequestParser($httpParser, $psrFactory, $tempFileManager);
        $responseWriter = new \Duyler\HttpServer\Parser\ResponseWriter();
        $metrics = new ServerMetrics();

        $requestProcessor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $pool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
        );

        $manager = new ConnectionManager(
            $pool,
            $httpParser,
            $requestProcessor,
            $metrics,
            $config,
            $logger,
        );

        $requestProcessor->setConnectionManager($manager);

        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.5', 'port' => 5500]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $manager->acceptFromServerSocket($socket, 10, false);
        $connection = $manager->getAll()[0];
        $manager->closeConnectionWithMetrics($connection);

        $this->assertSame(0, $pool->count());
    }

    #[Test]
    public function read_from_connection_direct_closes_on_invalid(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(false);
        $connection->expects($this->once())->method('close');

        $this->manager->readFromConnectionDirect($connection, 8192, static fn() => null);
    }

    #[Test]
    public function read_from_connection_direct_closes_when_read_fails(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('read')->willReturn(false);
        $connection->expects($this->once())->method('close');

        $this->manager->readFromConnectionDirect($connection, 8192, static fn() => null);
    }

    #[Test]
    public function read_from_connection_direct_closes_when_read_empty(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('read')->willReturn('');
        $connection->expects($this->once())->method('close');

        $this->manager->readFromConnectionDirect($connection, 8192, static fn() => null);
    }

    #[Test]
    public function read_from_connection_direct_closes_when_closed_after_append(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('read')->willReturn('some data');
        $connection->method('isClosed')->willReturn(true);
        $connection->expects($this->once())->method('close');

        $this->manager->readFromConnectionDirect($connection, 8192, static fn() => null);
    }

    #[Test]
    public function read_from_connection_direct_calls_callback_on_success(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('read')->willReturn('HTTP data');
        $connection->method('isClosed')->willReturn(false);

        $callbackCalled = false;
        $callbackConnection = null;
        $callback = function (ConnectionInterface $conn) use (&$callbackCalled, &$callbackConnection): void {
            $callbackCalled = true;
            $callbackConnection = $conn;
        };

        $this->manager->readFromConnectionDirect($connection, 8192, $callback);
        $this->assertTrue($callbackCalled);
        $this->assertSame($connection, $callbackConnection);
    }

    #[Test]
    public function read_from_connection_returns_false_on_invalid(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(false);

        $result = $this->manager->readFromConnection($connection, 8192, static fn() => null);
        $this->assertFalse($result);
    }

    #[Test]
    public function read_from_connection_returns_false_when_socket_not_stream_resource(): void
    {
        /** @var SocketResourceInterface $mockSocket */
        $mockSocket = $this->createStub(SocketResourceInterface::class);
        $mockSocket->method('isValid')->willReturn(true);

        /** @var ConnectionInterface $connection */
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('getSocket')->willReturn($mockSocket);

        $result = $this->manager->readFromConnection($connection, 8192, static fn() => null);
        $this->assertFalse($result);
    }

    #[Test]
    public function close_connection_with_metrics_removes_from_processor(): void
    {
        /** @var SocketInterface $socket */
        $socket = $this->createStub(SocketInterface::class);
        /** @var SocketResourceInterface $clientResource */
        $clientResource = $this->createStub(SocketResourceInterface::class);

        $clientResource->method('isValid')->willReturn(true);
        $clientResource->method('getPeerName')->willReturn(['ip' => '10.0.0.1', 'port' => 1234]);

        $socket->method('accept')->willReturnOnConsecutiveCalls($clientResource, false);

        $this->manager->acceptFromServerSocket($socket, 10, false);
        $connection = $this->manager->getAll()[0];

        $this->manager->closeConnectionWithMetrics($connection);

        $this->assertSame(0, $this->manager->count());
        $this->assertSame(1, $this->metrics->getMetrics()['total_connections']);
        $this->assertSame(1, $this->metrics->getMetrics()['closed_connections']);
    }

    #[Test]
    public function read_from_connection_direct_appends_data_to_buffer(): void
    {
        /** @var ConnectionInterface $connection */
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('read')->willReturn('buffer content');
        $connection->method('isClosed')->willReturn(false);
        $connection->expects($this->once())->method('appendToBuffer')->with('buffer content');

        $this->manager->readFromConnectionDirect($connection, 8192, static fn() => null);
    }
}
