<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Upload\TempFileManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

final class HttpRequestProcessorOrphanCleanupTest extends TestCase
{
    private TempFileManager $tempFileManager;

    private HttpRequestProcessor $processor;

    #[Override]
    protected function setUp(): void
    {
        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $this->tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $this->tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new ConnectionPool(100);
        $metrics = new ServerMetrics();

        $this->processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $this->tempFileManager,
            null,
            null,
            new NullLogger(),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tempFileManager->cleanup();
    }

    #[Test]
    public function remove_connections_by_connection_removes_matching_entries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $this->setRequestConnections([
            'req_0' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ],
            'req_1' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ],
        ]);

        $this->assertSame(2, $this->processor->getPendingRequestCount());

        $this->processor->removeConnectionsByConnection($connection);

        $this->assertSame(1, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function remove_connections_by_connection_handles_no_matches(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $this->setRequestConnections([
            'req_0' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ],
        ]);

        $this->processor->removeConnectionsByConnection($connection);

        $this->assertSame(1, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function remove_connections_by_connection_removes_all_entries_for_same_connection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $this->setRequestConnections([
            'req_0' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ],
            'req_1' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ],
            'req_2' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ],
        ]);

        $this->assertSame(3, $this->processor->getPendingRequestCount());

        $this->processor->removeConnectionsByConnection($connection);

        $this->assertSame(0, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function remove_connections_by_connection_on_empty_map(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $this->setRequestConnections([]);

        $this->processor->removeConnectionsByConnection($connection);

        $this->assertSame(0, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function thousand_connections_create_close_does_not_leak(): void
    {
        $connections = [];
        $entries = [];

        for ($i = 0; $i < 1000; $i++) {
            $conn = $this->createMock(ConnectionInterface::class);
            $connections[] = $conn;
            $entries["req_{$i}"] = [
                'connection' => $conn,
                'timestamp' => microtime(true),
                'cors_origin' => null,
            ];
        }

        $this->setRequestConnections($entries);
        $this->assertSame(1000, $this->processor->getPendingRequestCount());

        foreach ($connections as $connection) {
            $this->processor->removeConnectionsByConnection($connection);
        }

        $this->assertSame(0, $this->processor->getPendingRequestCount());
    }

    private function setRequestConnections(array $connections): void
    {
        $reflection = new ReflectionProperty($this->processor, 'requestConnections');
        $reflection->setValue($this->processor, $connections);
    }
}
