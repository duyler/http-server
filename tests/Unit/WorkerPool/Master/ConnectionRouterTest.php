<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Master;

use Duyler\HttpServer\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\HttpServer\WorkerPool\Master\ConnectionRouter;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionRouterTest extends TestCase
{
    private ConnectionRouter $router;
    private LeastConnectionsBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->balancer = new LeastConnectionsBalancer();
        $this->router = new ConnectionRouter($this->balancer);
    }

    #[Test]
    public function can_get_balancer(): void
    {
        $balancer = $this->router->getBalancer();

        $this->assertSame($this->balancer, $balancer);
    }

    #[Test]
    public function route_returns_false_when_no_workers_available(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($clientSocket === false) {
            $this->markTestSkipped('Cannot create socket');
        }

        $result = $this->router->route(
            clientSocket: $clientSocket,
            workers: [],
            workerSockets: [],
        );

        $this->assertFalse($result);
    }

    #[Test]
    public function route_returns_false_when_worker_socket_not_found(): void
    {
        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($clientSocket === false) {
            $this->markTestSkipped('Cannot create socket');
        }

        $workers = [
            1 => new ProcessInfo(
                workerId: 1,
                pid: 12345,
                state: ProcessState::Ready,
            ),
        ];

        $result = $this->router->route(
            clientSocket: $clientSocket,
            workers: $workers,
            workerSockets: [],
        );

        $this->assertFalse($result);
    }
}
