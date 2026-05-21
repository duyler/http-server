<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionManager;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Processor\RequestQueue;
use Duyler\HttpServer\Processor\ResponseSender;
use Duyler\HttpServer\Upload\TempFileManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

final class ConnectionManagerEdgeCasesTest extends TestCase
{
    private ConnectionManager $manager;

    private ConnectionPool $pool;

    private ServerMetrics $metrics;

    /** @var resource */
    private mixed $socket;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->pool = new ConnectionPool(100);
        $this->metrics = new ServerMetrics();

        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $this->pool,
            $this->metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );

        $this->manager = new ConnectionManager(
            $this->pool,
            $httpParser,
            $processor,
            $this->metrics,
            $config,
            new NullLogger(),
        );

        $processor->setConnectionManager($this->manager);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    #[Test]
    public function close_connection_with_metrics_in_debug_mode(): void
    {
        $config = new ServerConfig(debugMode: true);
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $pool = new ConnectionPool(100);
        $metrics = new ServerMetrics();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $pool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );

        $manager = new ConnectionManager(
            $pool,
            $httpParser,
            $processor,
            $metrics,
            $config,
        );

        $processor->setConnectionManager($manager);

        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $pool->add($connection);

        $manager->closeConnectionWithMetrics($connection);

        $this->assertSame(0, $pool->count());
    }

    #[Test]
    public function read_from_connection_returns_false_for_invalid_connection(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);
        $connection->close();

        $result = $this->manager->readFromConnection(
            $connection,
            8192,
            fn() => null,
        );

        $this->assertFalse($result);
    }

    #[Test]
    public function read_from_connection_direct_processes_data(): void
    {
        $socket = fopen('php://memory', 'r+');
        fwrite($socket, "GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");
        rewind($socket);

        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $callbackInvoked = false;
        $callback = function () use (&$callbackInvoked): void {
            $callbackInvoked = true;
        };

        $this->manager->readFromConnectionDirect($connection, 8192, $callback);

        $this->assertTrue($callbackInvoked);

        fclose($socket);
    }

    #[Test]
    public function cleanup_timed_out_removes_old_connections(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $this->pool->add($connection);

        $reflection = new ReflectionProperty(Connection::class, 'lastActivityTime');
        $reflection->setValue($connection, microtime(true) - 100);

        $removed = $this->manager->cleanupTimedOut(50);

        $this->assertSame(1, $removed);
        $this->assertSame(0, $this->pool->count());
    }

    #[Test]
    public function add_and_remove_connection(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $this->manager->add($connection);
        $this->assertSame(1, $this->manager->count());

        $this->manager->remove($connection);
        $this->assertSame(0, $this->manager->count());

        fclose($socket);
    }

    #[Test]
    public function close_all_removes_everything(): void
    {
        $socket1 = fopen('php://memory', 'r+');
        $socket2 = fopen('php://memory', 'r+');
        $resource1 = new \Duyler\HttpServer\Socket\StreamSocketResource($socket1);
        $resource2 = new \Duyler\HttpServer\Socket\StreamSocketResource($socket2);

        $conn1 = new Connection($resource1, '127.0.0.1', 12345);
        $conn2 = new Connection($resource2, '127.0.0.1', 12346);

        $this->manager->add($conn1);
        $this->manager->add($conn2);
        $this->assertSame(2, $this->manager->count());

        $this->manager->closeAll();
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function get_all_returns_all_connections(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $this->manager->add($connection);

        $all = $this->manager->getAll();
        $this->assertCount(1, $all);

        fclose($socket);
    }

    #[Test]
    public function remove_timed_out_returns_count(): void
    {
        $socket = fopen('php://memory', 'r+');
        $socketResource = new \Duyler\HttpServer\Socket\StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);

        $this->pool->add($connection);

        $reflection = new ReflectionProperty(Connection::class, 'lastActivityTime');
        $reflection->setValue($connection, microtime(true) - 100);

        $result = $this->manager->removeTimedOut(50);

        $this->assertSame(1, $result);
    }

    #[Test]
    public function set_logger_updates_logger(): void
    {
        $logger = new NullLogger();
        $this->manager->setLogger($logger);

        $this->assertSame(0, $this->manager->count());
    }
}
