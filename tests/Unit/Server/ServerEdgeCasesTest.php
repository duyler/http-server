<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Fiber;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

final class ServerEdgeCasesTest extends TestCase
{
    private int $nextPort = 19100;

    private ?Server $server = null;

    private function nextPort(): int
    {
        return $this->nextPort++;
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
            } catch (Throwable) {
            }
            try {
                $this->server->reset();
            } catch (Throwable) {
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function start_returns_true_when_already_running(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->start();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->server->setLogger($logger);

        $result = $this->server->start();
        $this->assertTrue($result);
    }

    #[Test]
    public function stop_is_noop_when_not_running(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->stop();
        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function reset_clears_state_after_start(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->start();

        $this->server->reset();

        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function restart_returns_true_on_success(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $result = $this->server->restart();
        $this->assertTrue($result);
    }

    #[Test]
    public function shutdown_returns_true_when_not_running(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->server->setLogger($logger);

        $result = $this->server->shutdown(5);
        $this->assertTrue($result);
    }

    #[Test]
    public function shutdown_completes_gracefully_with_no_connections(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->start();

        $result = $this->server->shutdown(1);
        $this->assertTrue($result);
    }

    #[Test]
    public function get_metrics_includes_memory_info(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->start();

        $metrics = $this->server->getMetrics();

        $this->assertArrayHasKey('memory_usage', $metrics);
        $this->assertArrayHasKey('memory_peak', $metrics);
        $this->assertArrayHasKey('memory_limit', $metrics);
        $this->assertArrayHasKey('memory_usage_percent', $metrics);
    }

    #[Test]
    public function get_static_cache_stats_returns_null_without_handler(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->assertNull($this->server->getStaticCacheStats());
    }

    #[Test]
    public function set_worker_id_changes_mode(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->setWorkerId(42);

        $this->assertSame(42, $this->server->getWorkerId());
        $this->assertSame(\Duyler\HttpServer\Config\ServerMode::WorkerPool, $this->server->getMode());
    }

    #[Test]
    public function start_returns_is_running_in_worker_pool_mode(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->server->setLogger($logger);

        $this->server->setWorkerId(1);

        $result = $this->server->start();
        $this->assertTrue($result);
    }

    #[Test]
    public function event_loop_active_flag(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $this->assertFalse($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(true);
        $this->assertTrue($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(false);
        $this->assertFalse($this->server->isEventLoopActive());
    }

    #[Test]
    public function register_and_unregister_fiber(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $fiber = new Fiber(function (): void {});

        $this->server->registerFiber($fiber);

        $result = $this->server->unregisterFiber($fiber);
        $this->assertTrue($result);
    }

    #[Test]
    public function unregister_fiber_returns_false_for_unknown(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $fiber = new Fiber(function (): void {});

        $result = $this->server->unregisterFiber($fiber);
        $this->assertFalse($result);
    }

    #[Test]
    public function get_socket_resource_returns_external_when_set(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->setExternalSocketResource('test_resource');

        $resource = $this->server->getSocketResource();
        $this->assertSame('test_resource', $resource);
    }

    #[Test]
    public function notification_enable_returns_resource(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->enableNotification();

        $resource = $this->server->getSocketResource();
        $this->assertNotNull($resource);

        $this->server->disableNotification();
    }

    #[Test]
    public function has_request_returns_false_when_empty(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->start();

        $result = $this->server->hasRequest();
        $this->assertFalse($result);
    }

    #[Test]
    public function get_request_returns_null_when_empty(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $this->server->setLogger($logger);

        $result = $this->server->getRequest();
        $this->assertNull($result);
    }

    #[Test]
    public function attach_websocket_and_stop(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $wsServer = new \Duyler\HttpServer\WebSocket\WebSocketServer(
            new \Duyler\HttpServer\WebSocket\WebSocketConfig(),
        );

        $this->server->attachWebSocket('/ws', $wsServer);
        $this->server->start();

        $this->assertTrue($this->server->start());

        $this->server->stop();
    }

    #[Test]
    public function reset_with_websocket_resets_handler(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $wsServer = new \Duyler\HttpServer\WebSocket\WebSocketServer(
            new \Duyler\HttpServer\WebSocket\WebSocketConfig(),
        );

        $this->server->attachWebSocket('/ws', $wsServer);
        $this->server->start();

        $this->server->reset();

        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function has_pending_response_returns_false_initially(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->assertFalse($this->server->hasPendingResponse());
    }

    #[Test]
    public function get_pending_request_id_returns_null_initially(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->assertNull($this->server->getPendingRequestId());
    }

    #[Test]
    public function start_fails_with_ssl_without_cert(): void
    {
        $this->expectException(\Duyler\HttpServer\Exception\InvalidConfigException::class);

        new ServerConfig(ssl: true, sslCert: null, sslKey: null, host: '127.0.0.1', port: $this->nextPort());
    }

    #[Test]
    public function enable_notification_returns_socket(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $this->server->enableNotification();

        $resource = $this->server->getSocketResource();
        $this->assertNotNull($resource);

        $this->server->disableNotification();
    }

    #[Test]
    public function notification_read_stream_returns_null_when_disabled(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $result = $this->server->getNotificationReadStream();
        $this->assertNull($result);
    }

    #[Test]
    public function start_watchers_throws_without_notification(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $this->expectException(\Duyler\HttpServer\Exception\ServerException::class);

        $this->server->startWatchers();
    }

    #[Test]
    public function stop_watchers_is_safe_when_none_started(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->stopWatchers();
        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function respond_delegates_to_processor(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $response = new \Nyholm\Psr7\Response(200, [], 'OK');
        $responseData = new \Duyler\HttpServer\Dto\ResponseData('req_0', $response);

        $this->server->respond($responseData);

        $this->assertFalse($this->server->hasPendingResponse());
    }

    #[Test]
    public function get_socket_resource_returns_listening_socket_when_started(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));
        $this->server->start();

        $resource = $this->server->getSocketResource();
        $this->assertNotNull($resource);
    }

    #[Test]
    public function get_mode_returns_standalone_by_default(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $this->assertSame(\Duyler\HttpServer\Config\ServerMode::Standalone, $this->server->getMode());
    }

    #[Test]
    public function get_worker_id_returns_null_by_default(): void
    {
        $this->server = new Server(new ServerConfig(host: '127.0.0.1', port: $this->nextPort()));

        $this->assertNull($this->server->getWorkerId());
    }
}
