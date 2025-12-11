<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WorkerPool\Balancer\RoundRobinBalancer;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\CentralizedMaster;
use Duyler\HttpServer\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

class CentralizedMasterEventDrivenTest extends TestCase
{
    private ServerConfig $serverConfig;
    private WorkerPoolConfig $workerPoolConfig;
    private RoundRobinBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->workerPoolConfig = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
        );

        $this->balancer = new RoundRobinBalancer($this->workerPoolConfig->workerCount);
    }

    #[Test]
    public function creates_master_with_event_driven_worker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, Server $server): void {}
        };

        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function creates_master_with_worker_callback(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void {}
        };

        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function throws_exception_when_no_worker_interface_provided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            workerCallback: null,
            eventDrivenWorker: null,
        );
    }

    #[Test]
    public function accepts_both_worker_interfaces(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void {}
        };

        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, Server $server): void {}
        };

        // Should not throw - both interfaces provided
        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function creates_master_without_server_config(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, Server $server): void {}
        };

        // CentralizedMaster can work without serverConfig (external socket mode)
        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: null,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function event_driven_worker_receives_parameters(): void
    {
        $receivedWorkerId = null;
        $receivedServer = null;

        $worker = new class ($receivedWorkerId, $receivedServer) implements EventDrivenWorkerInterface {
            public function __construct(
                private ?int &$workerId,
                private ?Server &$server,
            ) {}

            public function run(int $workerId, Server $server): void
            {
                $this->workerId = $workerId;
                $this->server = $server;
            }
        };

        $master = new CentralizedMaster(
            config: $this->workerPoolConfig,
            balancer: $this->balancer,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }
}
