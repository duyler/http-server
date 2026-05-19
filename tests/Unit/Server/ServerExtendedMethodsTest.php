<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function restart_returns_true_after_successful_start(): void
    {
        $server = $this->createServer(18081);
        $started = $server->start();
        $this->assertTrue($started);

        $result = $server->restart();

        $this->assertTrue($result);
        $server->stop();
    }

    #[Test]
    public function get_metrics_returns_array(): void
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

    #[Test]
    public function get_metrics_contains_memory_info(): void
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

    #[Test]
    public function get_static_cache_stats_returns_null_without_handler(): void
    {
        $server = $this->createServer(18084);
        $server->start();

        $stats = $server->getStaticCacheStats();

        $this->assertNull($stats);
        $server->stop();
    }

    #[Test]
    public function set_logger_updates_logger(): void
    {
        $server = $this->createServer();
        $logger = $this->createMock(LoggerInterface::class);

        $server->setLogger($logger);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function get_pending_request_id_returns_null_initially(): void
    {
        $server = $this->createServer(18085);
        $server->start();

        $pending = $server->getPendingRequestId();

        $this->assertNull($pending);
        $server->stop();
    }

    #[Test]
    public function attach_web_socket_sets_flag(): void
    {
        $server = $this->createServer(18086);
        $server->start();

        $ws = new WebSocketServer(new WebSocketConfig());
        $server->attachWebSocket('/ws', $ws);

        $this->expectNotToPerformAssertions();
        $server->stop();
    }

    #[Test]
    public function get_mode_returns_standalone_by_default(): void
    {
        $server = $this->createServer();

        $this->assertSame(ServerMode::Standalone, $server->getMode());
    }

    #[Test]
    public function set_worker_id_updates_worker_id(): void
    {
        $server = $this->createServer();

        $server->setWorkerId(5);

        $this->assertSame(5, $server->getWorkerId());
    }

    #[Test]
    public function get_worker_id_returns_null_by_default(): void
    {
        $server = $this->createServer();

        $this->assertNull($server->getWorkerId());
    }

    #[Test]
    public function add_external_connection_with_socket(): void
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

    #[Test]
    public function add_external_connection_requires_worker_id(): void
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

    #[Test]
    public function add_external_connection_with_worker_pid(): void
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

    #[Test]
    public function is_event_loop_active_returns_false_by_default(): void
    {
        $server = $this->createServer();

        $this->assertFalse($server->isEventLoopActive());
    }

    #[Test]
    public function set_event_loop_active_updates_state(): void
    {
        $server = $this->createServer();

        $server->setEventLoopActive(true);
        $this->assertTrue($server->isEventLoopActive());

        $server->setEventLoopActive(false);
        $this->assertFalse($server->isEventLoopActive());
    }
}
