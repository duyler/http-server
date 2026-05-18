<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Fiber;
use Override;
use PHPUnit\Framework\TestCase;
use Throwable;

class ServerFiberTest extends TestCase
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
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testUnregisterFiberRemovesRegisteredFiber(): void
    {
        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber->start();
        $this->server->registerFiber($fiber);

        $result = $this->server->unregisterFiber($fiber);

        $this->assertTrue($result);
    }

    public function testUnregisterFiberReturnsFalseForNonRegisteredFiber(): void
    {
        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber->start();

        $result = $this->server->unregisterFiber($fiber);

        $this->assertFalse($result);
    }

    public function testUnregisterFiberReturnsFalseAfterSecondUnregister(): void
    {
        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber->start();
        $this->server->registerFiber($fiber);

        $firstResult = $this->server->unregisterFiber($fiber);
        $secondResult = $this->server->unregisterFiber($fiber);

        $this->assertTrue($firstResult);
        $this->assertFalse($secondResult);
    }

    public function testTerminatedFibersAreCleanedUpInHasRequest(): void
    {
        $this->server->start();

        $terminated = new Fiber(function (): void {
            // Terminates immediately
        });

        $suspended = new Fiber(function (): void {
            Fiber::suspend();
        });

        $terminated->start();
        $suspended->start();

        $this->server->registerFiber($terminated);
        $this->server->registerFiber($suspended);

        $this->assertTrue($terminated->isTerminated());
        $this->assertTrue($suspended->isSuspended());

        $this->server->hasRequest();

        // Suspended fiber should still be registered (can be unregistered)
        $unregisterResult = $this->server->unregisterFiber($suspended);
        $this->assertTrue($unregisterResult);

        // Terminated fiber should be cleaned up, so unregister returns false
        $terminatedUnregisterResult = $this->server->unregisterFiber($terminated);
        $this->assertFalse($terminatedUnregisterResult);

        $this->server->stop();
    }

    public function testMultipleTerminatedFibersAreCleanedUp(): void
    {
        $this->server->start();

        $fiber1 = new Fiber(function (): void {
            // Terminates immediately
        });

        $fiber2 = new Fiber(function (): void {
            // Terminates immediately
        });

        $fiber3 = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber1->start();
        $fiber2->start();
        $fiber3->start();

        $this->server->registerFiber($fiber1);
        $this->server->registerFiber($fiber2);
        $this->server->registerFiber($fiber3);

        $this->server->hasRequest();

        // Only fiber3 should remain registered
        $this->assertTrue($this->server->unregisterFiber($fiber3));
        $this->assertFalse($this->server->unregisterFiber($fiber1));
        $this->assertFalse($this->server->unregisterFiber($fiber2));

        $this->server->stop();
    }

    public function testResetClearsAllFibers(): void
    {
        $this->server->start();

        $fiber1 = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber2 = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber1->start();
        $fiber2->start();

        $this->server->registerFiber($fiber1);
        $this->server->registerFiber($fiber2);

        $this->server->reset();

        // After reset, all fibers should be cleared
        $this->assertFalse($this->server->unregisterFiber($fiber1));
        $this->assertFalse($this->server->unregisterFiber($fiber2));
    }

    public function testFiberArrayIsReindexedAfterCleanup(): void
    {
        $this->server->start();

        $fiber1 = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber2 = new Fiber(function (): void {
            // Terminates immediately
        });

        $fiber3 = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber1->start();
        $fiber2->start();
        $fiber3->start();

        $this->server->registerFiber($fiber1);
        $this->server->registerFiber($fiber2);
        $this->server->registerFiber($fiber3);

        $this->server->hasRequest();

        // After cleanup of terminated fiber2, remaining fibers should still be unregistrable
        $this->assertTrue($this->server->unregisterFiber($fiber1));
        $this->assertFalse($this->server->unregisterFiber($fiber2)); // Already cleaned up
        $this->assertTrue($this->server->unregisterFiber($fiber3));

        $this->server->stop();
    }

    public function testSuspendedFibersContinueToBeResumedAfterCleanup(): void
    {
        $this->server->start();

        $resumeCount = 0;

        $terminated = new Fiber(function (): void {
            // Terminates immediately
        });

        $suspended = new Fiber(function () use (&$resumeCount): void {
            while (true) {
                $resumeCount++;
                Fiber::suspend();
            }
        });

        $terminated->start();
        $suspended->start();

        $this->server->registerFiber($terminated);
        $this->server->registerFiber($suspended);

        $this->assertSame(1, $resumeCount);

        $this->server->hasRequest();
        $this->assertSame(2, $resumeCount);

        $this->server->hasRequest();
        $this->assertSame(3, $resumeCount);

        $this->server->stop();
    }
}
