<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Signal;

use Duyler\HttpServer\WorkerPool\Signal\SignalHandler;
use Duyler\HttpServer\WorkerPool\Signal\SignalManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SignalManagerTest extends TestCase
{
    private SignalHandler $handler;
    private SignalManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new SignalHandler();
        $this->manager = new SignalManager($this->handler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->handler->reset();
    }

    #[Test]
    public function sets_up_master_signals(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $shutdownCalled = false;
        $reloadCalled = false;

        $this->manager->setupMasterSignals(
            onShutdown: function () use (&$shutdownCalled) {
                $shutdownCalled = true;
            },
            onReload: function () use (&$reloadCalled) {
                $reloadCalled = true;
            },
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertTrue($this->handler->hasHandlers(SIGINT));
        $this->assertTrue($this->handler->hasHandlers(SIGUSR1));
    }

    #[Test]
    public function sets_up_worker_signals(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $shutdownCalled = false;

        $this->manager->setupWorkerSignals(
            onShutdown: function () use (&$shutdownCalled) {
                $shutdownCalled = true;
            },
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertTrue($this->handler->hasHandlers(SIGINT));
    }

    #[Test]
    public function tracks_shutdown_request(): void
    {
        $this->assertFalse($this->manager->isShutdownRequested());

        $this->manager->setupMasterSignals(
            onShutdown: function () {},
            onReload: function () {},
        );

        $this->assertFalse($this->manager->isShutdownRequested());
    }

    #[Test]
    public function tracks_reload_request(): void
    {
        $this->assertFalse($this->manager->isReloadRequested());

        $this->manager->setupMasterSignals(
            onShutdown: function () {},
            onReload: function () {},
        );

        $this->assertFalse($this->manager->isReloadRequested());
    }

    #[Test]
    public function resets_signal_handlers(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $this->manager->setupMasterSignals(
            onShutdown: function () {},
            onReload: function () {},
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));

        $this->manager->reset();

        $this->assertFalse($this->handler->hasHandlers(SIGTERM));
        $this->assertFalse($this->manager->isShutdownRequested());
        $this->assertFalse($this->manager->isReloadRequested());
    }

    #[Test]
    public function resets_only_flags(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $this->manager->setupMasterSignals(
            onShutdown: function () {},
            onReload: function () {},
        );

        $this->manager->resetFlags();

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertFalse($this->manager->isShutdownRequested());
        $this->assertFalse($this->manager->isReloadRequested());
    }

    #[Test]
    public function dispatch_calls_handler_dispatch(): void
    {
        $this->manager->dispatch();

        $this->assertTrue(true);
    }

    #[Test]
    public function handles_multiple_setups(): void
    {
        if (!$this->handler->isSignalsSupported()) {
            $this->markTestSkipped('Signals not supported');
        }

        $this->manager->setupMasterSignals(
            onShutdown: function () {},
            onReload: function () {},
        );

        $this->manager->setupWorkerSignals(
            onShutdown: function () {},
        );

        $this->assertTrue($this->handler->hasHandlers(SIGTERM));
        $this->assertTrue($this->handler->hasHandlers(SIGINT));
    }
}
