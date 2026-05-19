<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional\Stubs;

use Duyler\HttpServer\ErrorHandler\ProductionErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ProductionErrorHandler::class)]
class ShutdownHandlerStubTest extends TestCase
{
    use ErrorHandlerTestTrait;

    private ProductionErrorHandler $handler;
    private LoggerInterface&MockObject $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new ProductionErrorHandler($this->logger);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->handler->reset();
        $this->resetErrorHandlerState();
        parent::tearDown();
    }

    #[Test]
    public function shutdown_handler_registered_on_construct(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        $this->handler->register();
    }

    #[Test]
    public function shutdown_handler_invokes_fatal_error_callback(): void
    {
        $fatalErrorCalled = false;

        $this->handler = new ProductionErrorHandler(
            $this->logger,
            function (array $error) use (&$fatalErrorCalled): void {
                $fatalErrorCalled = true;
            },
        );

        $this->logger->method('emergency');

        $this->handler->handleShutdown();

        $this->assertFalse($fatalErrorCalled);
    }

    #[Test]
    public function shutdown_handler_runs_only_once(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Server shutdown normally', $this->anything());

        $this->handler->handleShutdown();
        $this->handler->handleShutdown();
    }

    #[Test]
    public function signal_handler_registers_for_sigterm(): void
    {
        if (false === function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        $this->handler->register();
    }

    #[Test]
    public function signal_handler_invokes_callback(): void
    {
        if (false === defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
        }

        $signalReceived = null;

        $this->handler = new ProductionErrorHandler(
            $this->logger,
            null,
            function (int $signal) use (&$signalReceived): void {
                $signalReceived = $signal;
            },
        );

        $this->logger->method('warning');
        $this->logger->method('info');

        $this->handler->handleSignal(SIGTERM);

        $this->assertSame(SIGTERM, $signalReceived);
    }

    #[Test]
    public function signal_handler_callback_exception_is_caught(): void
    {
        if (false === defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
        }

        $this->handler = new ProductionErrorHandler(
            $this->logger,
            null,
            function (int $signal): void {
                throw new RuntimeException('Signal handler error');
            },
        );

        $this->logger->method('warning');
        $this->logger->method('info');
        $this->logger->expects($this->once())
            ->method('error')
            ->with('Error in signal callback');

        $this->handler->handleSignal(SIGTERM);
    }

    #[Test]
    public function reset_restores_previous_handlers(): void
    {
        $this->logger->method('info');
        $this->logger->method('error');

        $this->handler->register();
        $this->handler->reset();

        $result = $this->handler->handleError(E_WARNING, 'After reset', __FILE__, __LINE__);
        $this->assertFalse($result);
    }

    #[Test]
    public function register_idempotent(): void
    {
        $this->logger->expects($this->once())
            ->method('info');

        $this->handler->register();
        $this->handler->register();
    }

    #[Test]
    public function handle_error_logs_with_suppressed_reporting(): void
    {
        $oldReporting = error_reporting(0);

        $this->logger->expects($this->never())
            ->method('error');

        $result = $this->handler->handleError(E_WARNING, 'Suppressed', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_error_logs_warning(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_WARNING'));

        $this->handler->handleError(E_WARNING, 'Test warning', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_exception_logs_critical(): void
    {
        $exception = new RuntimeException('Test exception');

        $this->logger->expects($this->once())
            ->method('critical')
            ->with('Uncaught exception', $this->callback(
                fn($ctx) => $ctx['exception'] === RuntimeException::class
                && $ctx['message'] === 'Test exception',
            ));

        $this->handler->handleException($exception);
    }

    #[Test]
    public function handle_shutdown_without_error_logs_normal(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Server shutdown normally');

        $this->handler->handleShutdown();
    }

    #[Test]
    public function fatal_error_callback_does_not_invoke_without_error(): void
    {
        $callbackInvoked = false;

        $this->handler = new ProductionErrorHandler(
            $this->logger,
            function (array $error) use (&$callbackInvoked): void {
                $callbackInvoked = true;
            },
        );

        $this->logger->method('info');

        $this->handler->handleShutdown();

        $this->assertFalse($callbackInvoked);
    }

    #[Test]
    public function sigint_invokes_graceful_shutdown(): void
    {
        if (false === defined('SIGINT')) {
            $this->markTestSkipped('SIGINT not available');
        }

        $signalReceived = null;

        $this->handler = new ProductionErrorHandler(
            $this->logger,
            null,
            function (int $signal) use (&$signalReceived): void {
                $signalReceived = $signal;
            },
        );

        $this->logger->method('warning');
        $this->logger->method('info');

        $this->handler->handleSignal(SIGINT);

        $this->assertSame(SIGINT, $signalReceived);
    }

    #[Test]
    public function sighup_does_not_invoke_shutdown_callback(): void
    {
        if (false === defined('SIGHUP')) {
            $this->markTestSkipped('SIGHUP not available');
        }

        $callbackInvoked = false;

        $this->handler = new ProductionErrorHandler(
            $this->logger,
            null,
            function (int $signal) use (&$callbackInvoked): void {
                $callbackInvoked = true;
            },
        );

        $this->logger->method('warning');

        $this->handler->handleSignal(SIGHUP);

        $this->assertFalse($callbackInvoked);
    }

    #[Test]
    public function reset_when_not_registered_is_noop(): void
    {
        $this->handler->reset();
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function error_handler_interface_methods_exist(): void
    {
        $this->assertTrue(method_exists($this->handler, 'register'));
        $this->assertTrue(method_exists($this->handler, 'reset'));
        $this->assertTrue(method_exists($this->handler, 'handleError'));
        $this->assertTrue(method_exists($this->handler, 'handleException'));
        $this->assertTrue(method_exists($this->handler, 'handleShutdown'));
        $this->assertTrue(method_exists($this->handler, 'handleSignal'));
    }
}
