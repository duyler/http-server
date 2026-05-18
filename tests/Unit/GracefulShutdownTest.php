<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\TestCase;
use Throwable;

class GracefulShutdownTest extends TestCase
{
    private Server $server;
    private int $port;

    #[Override]
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

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->server->stop();
            $this->server->reset();
        } catch (Throwable) {
        }
        parent::tearDown();
    }

    public function testShutdownOnStoppedServerReturnsTrue(): void
    {
        $result = $this->server->shutdown(1);
        $this->assertTrue($result);
    }

    public function testShutdownOnRunningServerWithNoConnections(): void
    {
        $this->server->start();

        $result = $this->server->shutdown(1);

        $this->assertTrue($result, 'Shutdown should succeed with no active connections');
    }

    public function testShutdownTwiceReturnsFalseOnSecondCall(): void
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

    public function testShutdownCompletesWithActiveConnection(): void
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

    public function testStopResetsShutdownFlag(): void
    {
        $this->server->start();

        $this->server->stop();

        $this->server->start();
        $result = $this->server->shutdown(1);

        $this->assertTrue($result);
    }

    public function testShutdownWaitsForRequestQueueToEmpty(): void
    {
        $this->server->start();

        $startTime = microtime(true);
        $result = $this->server->shutdown(2);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(2, $elapsed, 'Should complete quickly with empty queue');
    }

    public function testShutdownTimeoutForcesStop(): void
    {
        $this->server->start();

        $startTime = microtime(true);
        $result = $this->server->shutdown(1);
        $elapsed = microtime(true) - $startTime;

        $this->assertLessThanOrEqual(1.5, $elapsed, 'Should respect timeout');
    }

    public function testShutdownCompletesImmediatelyWithNoActiveWork(): void
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
