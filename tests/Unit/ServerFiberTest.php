<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Fiber;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerFiberTest extends TestCase
{
    private Server $server;

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

    #[Test]
    public function unregister_fiber_removes_registered_fiber(): void
    {
        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber->start();
        $this->server->registerFiber($fiber);

        $result = $this->server->unregisterFiber($fiber);

        $this->assertTrue($result);
    }

    #[Test]
    public function unregister_fiber_returns_false_for_non_registered_fiber(): void
    {
        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber->start();

        $result = $this->server->unregisterFiber($fiber);

        $this->assertFalse($result);
    }

    #[Test]
    public function unregister_fiber_returns_false_after_second_unregister(): void
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

    #[Test]
    public function terminated_fibers_are_cleaned_up_in_has_request(): void
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

    #[Test]
    public function multiple_terminated_fibers_are_cleaned_up(): void
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

    #[Test]
    public function reset_clears_all_fibers(): void
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

    #[Test]
    public function fiber_array_is_reindexed_after_cleanup(): void
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

    #[Test]
    public function suspended_fibers_continue_to_be_resumed_after_cleanup(): void
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
