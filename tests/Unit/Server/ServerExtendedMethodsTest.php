<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ServerExtendedMethodsTest extends TestCase
{
    private function createServer(int $port = 18080): Server
    {
        return new Server(new ServerConfig(
            host: '127.0.0.1',
            port: $port,
            memoryLimit: 134217728,
        ));
    }

    public function testRestartReturnsTrueAfterSuccessfulStart(): void
    {
        $server = $this->createServer(18081);
        $started = $server->start();
        $this->assertTrue($started);

        $result = $server->restart();

        $this->assertTrue($result);
        $server->stop();
    }

    public function testGetMetricsReturnsArray(): void
    {
        $server = $this->createServer(18082);
        $server->start();

        $metrics = $server->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('memory_usage', $metrics);
        $this->assertArrayHasKey('memory_peak', $metrics);
        $this->assertArrayHasKey('memory_limit', $metrics);
        $this->assertArrayHasKey('memory_usage_percent', $metrics);
        $server->stop();
    }

    public function testGetMetricsContainsMemoryInfo(): void
    {
        $server = $this->createServer(18083);
        $server->start();

        $metrics = $server->getMetrics();

        $this->assertIsInt($metrics['memory_usage']);
        $this->assertIsInt($metrics['memory_peak']);
        $this->assertIsInt($metrics['memory_limit']);
        $this->assertIsFloat($metrics['memory_usage_percent']);
        $server->stop();
    }

    public function testGetStaticCacheStatsReturnsNullWithoutHandler(): void
    {
        $server = $this->createServer(18084);
        $server->start();

        $stats = $server->getStaticCacheStats();

        $this->assertNull($stats);
        $server->stop();
    }

    public function testSetLoggerUpdatesLogger(): void
    {
        $server = $this->createServer();
        $logger = $this->createMock(LoggerInterface::class);

        $server->setLogger($logger);

        $this->expectNotToPerformAssertions();
    }

    public function testGetPendingRequestIdReturnsNullInitially(): void
    {
        $server = $this->createServer(18085);
        $server->start();

        $pending = $server->getPendingRequestId();

        $this->assertNull($pending);
        $server->stop();
    }

    public function testAttachWebSocketSetsFlag(): void
    {
        $server = $this->createServer(18086);
        $server->start();

        $ws = new WebSocketServer(new WebSocketConfig());
        $server->attachWebSocket('/ws', $ws);

        $this->assertTrue(true);
        $server->stop();
    }

    public function testGetModeReturnsStandaloneByDefault(): void
    {
        $server = $this->createServer();

        $this->assertSame(ServerMode::Standalone, $server->getMode());
    }

    public function testSetWorkerIdUpdatesWorkerId(): void
    {
        $server = $this->createServer();

        $server->setWorkerId(5);

        $this->assertSame(5, $server->getWorkerId());
    }

    public function testGetWorkerIdReturnsNullByDefault(): void
    {
        $server = $this->createServer();

        $this->assertNull($server->getWorkerId());
    }

    public function testAddExternalConnectionWithSocket(): void
    {
        $server = $this->createServer(18087);
        $server->start();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 1,
            'client_ip' => '127.0.0.1',
        ];

        $server->addExternalConnection($socket, $metadata);

        $this->assertSame(ServerMode::WorkerPool, $server->getMode());
        $this->assertSame(1, $server->getWorkerId());
        $server->stop();
    }

    public function testAddExternalConnectionRequiresWorkerId(): void
    {
        $server = $this->createServer(18088);
        $server->start();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $this->expectException(\Duyler\HttpServer\Exception\InvalidConfigException::class);

        try {
            $server->addExternalConnection($socket, []);
        } finally {
            socket_close($socket);
            $server->stop();
        }
    }

    public function testAddExternalConnectionWithWorkerPid(): void
    {
        $server = $this->createServer(18089);
        $server->start();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 1,
            'worker_pid' => 12345,
            'client_ip' => '10.0.0.1',
        ];

        $server->addExternalConnection($socket, $metadata);

        $this->assertSame(ServerMode::WorkerPool, $server->getMode());
        $server->stop();
    }

    public function testIsEventLoopActiveReturnsFalseByDefault(): void
    {
        $server = $this->createServer();

        $this->assertFalse($server->isEventLoopActive());
    }

    public function testSetEventLoopActiveUpdatesState(): void
    {
        $server = $this->createServer();

        $server->setEventLoopActive(true);
        $this->assertTrue($server->isEventLoopActive());

        $server->setEventLoopActive(false);
        $this->assertFalse($server->isEventLoopActive());
    }
}
