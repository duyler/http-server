<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Server;
use Fiber;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class ServerEventDrivenTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->server = new Server($config);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testSetsWorkerIdAndMode(): void
    {
        $this->assertNull($this->server->getWorkerId());
        $this->assertSame(ServerMode::Standalone, $this->server->getMode());

        $this->server->setWorkerId(5);

        $this->assertSame(5, $this->server->getWorkerId());
        $this->assertSame(ServerMode::WorkerPool, $this->server->getMode());
    }

    public function testSetsMultipleWorkerIds(): void
    {
        $this->server->setWorkerId(1);
        $this->assertSame(1, $this->server->getWorkerId());

        $this->server->setWorkerId(99);
        $this->assertSame(99, $this->server->getWorkerId());
    }

    public function testRegistersFiber(): void
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

    public function testRegistersMultipleFibers(): void
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

    public function testHasRequestResumesRegisteredFibers(): void
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

    public function testHasRequestHandlesTerminatedFibersGracefully(): void
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

    public function testHasRequestContinuesOnFiberError(): void
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

    public function testServerModeChangesToWorkerPoolAfterSetWorkerId(): void
    {
        $this->assertSame(ServerMode::Standalone, $this->server->getMode());

        $this->server->setWorkerId(1);

        $this->assertSame(ServerMode::WorkerPool, $this->server->getMode());
    }
}
