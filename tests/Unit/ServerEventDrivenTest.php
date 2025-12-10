<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Server;
use Fiber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ServerEventDrivenTest extends TestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->server = new Server($config);
    }

    #[Test]
    public function sets_worker_id_and_mode(): void
    {
        $this->assertNull($this->server->getWorkerId());
        $this->assertSame(ServerMode::Standalone, $this->server->getMode());

        $this->server->setWorkerId(5);

        $this->assertSame(5, $this->server->getWorkerId());
        $this->assertSame(ServerMode::WorkerPool, $this->server->getMode());
    }

    #[Test]
    public function sets_multiple_worker_ids(): void
    {
        $this->server->setWorkerId(1);
        $this->assertSame(1, $this->server->getWorkerId());

        $this->server->setWorkerId(99);
        $this->assertSame(99, $this->server->getWorkerId());
    }

    #[Test]
    public function registers_fiber(): void
    {
        $fiberExecuted = false;

        $fiber = new Fiber(function () use (&$fiberExecuted): void {
            $fiberExecuted = true;
            Fiber::suspend();
        });

        $fiber->start();
        $this->assertTrue($fiberExecuted);

        $this->server->registerFiber($fiber);

        // Fiber should be registered (no exception)
        $this->assertTrue(true);
    }

    #[Test]
    public function registers_multiple_fibers(): void
    {
        $counter = 0;

        $fiber1 = new Fiber(function () use (&$counter): void {
            $counter++;
            Fiber::suspend();
        });

        $fiber2 = new Fiber(function () use (&$counter): void {
            $counter++;
            Fiber::suspend();
        });

        $fiber1->start();
        $fiber2->start();

        $this->server->registerFiber($fiber1);
        $this->server->registerFiber($fiber2);

        $this->assertSame(2, $counter);
    }

    #[Test]
    public function has_request_resumes_registered_fibers(): void
    {
        $this->server->start();

        $resumeCount = 0;

        $fiber = new Fiber(function () use (&$resumeCount): void {
            while (true) {
                $resumeCount++;
                Fiber::suspend();
            }
        });

        $fiber->start();
        $this->assertSame(1, $resumeCount);

        $this->server->registerFiber($fiber);

        // Call hasRequest() which should resume the fiber
        $this->server->hasRequest();
        $this->assertSame(2, $resumeCount);

        // Call again
        $this->server->hasRequest();
        $this->assertSame(3, $resumeCount);

        $this->server->stop();
    }

    #[Test]
    public function has_request_handles_terminated_fibers_gracefully(): void
    {
        $this->server->start();

        $executed = false;

        $fiber = new Fiber(function () use (&$executed): void {
            $executed = true;
            // Fiber terminates (no suspend)
        });

        $fiber->start();
        $this->assertTrue($executed);
        $this->assertTrue($fiber->isTerminated());

        $this->server->registerFiber($fiber);

        // Should not throw exception even if fiber is terminated
        $this->server->hasRequest();

        $this->server->stop();
        $this->assertTrue(true);
    }

    #[Test]
    public function has_request_continues_on_fiber_error(): void
    {
        $this->server->start();

        $fiber = new Fiber(function (): never {
            Fiber::suspend();
            throw new RuntimeException('Fiber error');
        });

        $fiber->start();
        $this->server->registerFiber($fiber);

        // Should catch error and continue
        $this->server->hasRequest();

        $this->server->stop();
        $this->assertTrue(true);
    }

    #[Test]
    public function server_mode_changes_to_worker_pool_after_set_worker_id(): void
    {
        $this->assertSame(ServerMode::Standalone, $this->server->getMode());

        $this->server->setWorkerId(1);

        $this->assertSame(ServerMode::WorkerPool, $this->server->getMode());
    }
}
