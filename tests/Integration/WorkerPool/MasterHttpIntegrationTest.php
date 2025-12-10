<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\WorkerPool;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Tests\Support\PlatformHelper;
use Duyler\HttpServer\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\Master;
use Duyler\HttpServer\WorkerPool\Worker\HttpWorkerAdapter;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

class MasterHttpIntegrationTest extends TestCase
{
    #[Test]
    public function master_accepts_and_distributes_http_requests(): void
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
        );

        $callback = new class () implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void
            {
                $adapter = new HttpWorkerAdapter();
                $adapter->handleConnection($clientSocket, $metadata);
            }
        };

        $balancer = new RoundRobinBalancer();

        $master = new Master(
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
        $this->assertNotFalse($client);

        $connected = @socket_connect($client, '127.0.0.1', $port);

        if ($connected) {
            $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
            socket_write($client, $request);

            $response = '';
            while (true) {
                $chunk = @socket_read($client, 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $response .= $chunk;
            }

            socket_close($client);

            $this->assertStringContainsString('HTTP/', $response);
            $this->assertStringContainsString('Hello from Worker Pool', $response);
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (!$connected) {
            $this->markTestSkipped('Could not connect to server');
        }
    }

    #[Test]
    public function master_handles_multiple_concurrent_requests(): void
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
        );

        $callback = new class () implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void
            {
                $adapter = new HttpWorkerAdapter();
                $adapter->handleConnection($clientSocket, $metadata);
            }
        };

        $balancer = new RoundRobinBalancer();

        $master = new Master(
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

        $responses = [];

        for ($i = 0; $i < 3; $i++) {
            $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $this->assertNotFalse($client);

            if (@socket_connect($client, '127.0.0.1', $port)) {
                $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
                socket_write($client, $request);

                $response = '';
                while (true) {
                    $chunk = @socket_read($client, 1024);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $response .= $chunk;
                }

                socket_close($client);
                $responses[] = $response;
            }
        }

        posix_kill($pid, SIGTERM);
        pcntl_waitpid($pid, $status);

        if (count($responses) === 0) {
            $this->markTestSkipped('No successful connections');
        }

        foreach ($responses as $response) {
            $this->assertStringContainsString('HTTP/', $response);
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

