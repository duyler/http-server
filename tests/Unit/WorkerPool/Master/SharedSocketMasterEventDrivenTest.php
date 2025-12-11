<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\SharedSocketMaster;
use Duyler\HttpServer\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

class SharedSocketMasterEventDrivenTest extends TestCase
{
    private ServerConfig $serverConfig;
    private WorkerPoolConfig $workerPoolConfig;

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
    }

    #[Test]
    public function creates_master_with_event_driven_worker(): void
    {
        $worker = new class implements EventDrivenWorkerInterface {
            public function run(int $workerId, Server $server): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function creates_master_with_worker_callback(): void
    {
        $callback = new class implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void {}
        };

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function throws_exception_when_no_worker_interface_provided(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Either workerCallback or eventDrivenWorker must be provided');

        new SharedSocketMaster(
            config: $this->workerPoolConfig,
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
        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            workerCallback: $callback,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function event_driven_worker_can_be_instantiated(): void
    {
        $workerInitialized = false;

        $worker = new class ($workerInitialized) implements EventDrivenWorkerInterface {
            public function __construct(
                private bool &$initialized,
            ) {
                $this->initialized = true;
            }

            public function run(int $workerId, Server $server): void {}
        };

        $this->assertTrue($workerInitialized);

        $master = new SharedSocketMaster(
            config: $this->workerPoolConfig,
            serverConfig: $this->serverConfig,
            eventDrivenWorker: $worker,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }
}
