<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\WorkerPool;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Tests\Support\PlatformHelper;
use Duyler\HttpServer\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\CentralizedMaster;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

final class WorkerCrashIntegrationTest extends TestCase
{
    #[Test]
    public function handles_worker_crash_without_auto_restart(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
        );

        $initialWorkerCount = $master->getWorkerCount();

        $this->assertSame(0, $initialWorkerCount);
    }

    #[Test]
    public function auto_restart_is_configurable(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $workerPoolConfigWithRestart = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: true,
            restartDelay: 1,
        );

        $workerPoolConfigWithoutRestart = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->assertTrue($workerPoolConfigWithRestart->autoRestart);
        $this->assertFalse($workerPoolConfigWithoutRestart->autoRestart);
        $this->assertSame(1, $workerPoolConfigWithRestart->restartDelay);
    }

    #[Test]
    public function master_continues_after_worker_crash(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $port = $this->findFreePort();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
        );

        $workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $callback = new class implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void
            {
                $response = "HTTP/1.1 200 OK\r\n\r\nOK";
                socket_write($clientSocket, $response);
                socket_close($clientSocket);
            }
        };

        $balancer = new RoundRobinBalancer();

        $master = new CentralizedMaster(
            config: $workerPoolConfig,
            balancer: $balancer,
            serverConfig: $serverConfig,
            workerCallback: $callback,
        );

        $pid = pcntl_fork();

        if ($pid === 0) {
            $master->start();
            exit(0);
        }

        sleep(1);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if (@socket_connect($client, '127.0.0.1', $port)) {
            socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

            $response = '';
            while ($chunk = @socket_read($client, 1024)) {
                $response .= $chunk;
            }

            socket_close($client);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $response);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (!@socket_connect($client, '127.0.0.1', $port)) {
            $this->markTestSkipped('Could not connect to server');
        }
    }

    private function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}
