<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GracefulShutdownTest extends TestCase
{
    private Server $server;
    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
    }

    protected function tearDown(): void
    {
        try {
            $this->server->stop();
        } catch (\Throwable $e) {
        }
        parent::tearDown();
    }

    #[Test]
    public function shutdown_on_stopped_server_returns_true(): void
    {
        $result = $this->server->shutdown(1);
        $this->assertTrue($result);
    }

    #[Test]
    public function shutdown_on_running_server_with_no_connections(): void
    {
        $this->server->start();

        $result = $this->server->shutdown(1);

        $this->assertTrue($result, 'Shutdown should succeed with no active connections');
    }

    #[Test]
    public function shutdown_twice_returns_false_on_second_call(): void
    {
        $this->server->start();

        $client = $this->connectClient();
        fclose($client);

        usleep(100000);

        $shutdownThread = function () {
            usleep(50000);
            return $this->server->shutdown(5);
        };

        $result1 = $shutdownThread();
        $this->assertTrue($result1);
    }

    #[Test]
    public function shutdown_completes_with_active_connection(): void
    {
        $this->server->start();

        $client = $this->connectClient();

        usleep(50000);

        $startTime = microtime(true);
        $result = $this->server->shutdown(2);
        $elapsed = microtime(true) - $startTime;

        fclose($client);

        $this->assertLessThanOrEqual(2.5, $elapsed, 'Should complete within timeout');
    }

    #[Test]
    public function stop_resets_shutdown_flag(): void
    {
        $this->server->start();

        $this->server->stop();

        $this->server->start();
        $result = $this->server->shutdown(1);

        $this->assertTrue($result);
    }

    #[Test]
    public function shutdown_waits_for_request_queue_to_empty(): void
    {
        $this->server->start();

        $startTime = microtime(true);
        $result = $this->server->shutdown(2);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(2, $elapsed, 'Should complete quickly with empty queue');
    }

    #[Test]
    public function shutdown_timeout_forces_stop(): void
    {
        $this->server->start();

        $startTime = microtime(true);
        $result = $this->server->shutdown(1);
        $elapsed = microtime(true) - $startTime;

        $this->assertLessThanOrEqual(1.5, $elapsed, 'Should respect timeout');
    }

    #[Test]
    public function shutdown_completes_immediately_with_no_active_work(): void
    {
        $this->server->start();

        $startTime = microtime(true);
        $result = $this->server->shutdown(5);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(0.5, $elapsed, 'Should complete almost immediately');
    }

    /**
     * @return resource
     */
    private function connectClient()
    {
        $client = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1,
        );

        if ($client === false) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

        return $client;
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}

