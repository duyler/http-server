<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler\New;

use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Duyler\HttpServer\Tests\Support\ErrorReportingScope;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ErrorHandlerTest extends TestCase
{
    use ErrorHandlerTestTrait;
    use ErrorReportingScope;

    private ErrorHandler $handler;
    private LoggerInterface&MockObject $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new ErrorHandler($this->logger);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->handler->reset();
        $this->resetErrorHandlerState();
        parent::tearDown();
    }

    #[Test]
    public function register_logs_info(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        $this->handler->register();
    }

    #[Test]
    public function register_only_once(): void
    {
        $this->logger->expects($this->once())
            ->method('info');

        $this->handler->register();
        $this->handler->register();
    }

    #[Test]
    public function handle_error_with_suppressed_reporting(): void
    {
        $this->withSuppressedErrors(function (): void {
            $this->logger->expects($this->never())
                ->method('error');

            $result = $this->handler->handleError(E_WARNING, 'Test', __FILE__, __LINE__);

            $this->assertFalse($result);
        });
    }

    #[Test]
    public function handle_error_logs_error(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_WARNING'));

        $result = $this->handler->handleError(E_WARNING, 'Test warning', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_error_for_fatal_error(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_ERROR'));

        $result = $this->handler->handleError(E_ERROR, 'Fatal error', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_error_for_user_error(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_USER_ERROR'));

        $result = $this->handler->handleError(E_USER_ERROR, 'User error', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_exception(): void
    {
        $exception = new RuntimeException('Test exception');

        $this->logger->expects($this->once())
            ->method('critical')
            ->with('Uncaught exception', $this->callback(
                fn($ctx)
                => $ctx['exception'] === RuntimeException::class
                && $ctx['message'] === 'Test exception',
            ));

        $this->handler->handleException($exception);
    }

    #[Test]
    public function handle_shutdown_without_error(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Server shutdown normally', $this->callback(fn($ctx) => is_array($ctx)));

        $this->handler->handleShutdown();
    }

    #[Test]
    public function handle_shutdown_only_runs_once(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Server shutdown normally');

        $this->handler->handleShutdown();
        $this->handler->handleShutdown();
    }

    #[Test]
    public function handle_signal(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(
                fn($ctx)
                => $ctx['signal'] === SIGTERM && $ctx['name'] === 'SIGTERM',
            ));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Graceful shutdown initiated');

        $this->handler->handleSignal(SIGTERM);
    }

    #[Test]
    public function handle_signal_with_callback(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
        }

        $callbackInvoked = false;

        $this->handler = new ErrorHandler(
            $this->logger,
            null,
            function (int $signal) use (&$callbackInvoked): void {
                $callbackInvoked = true;
            },
        );

        $this->logger->method('warning');
        $this->logger->method('info');

        $this->handler->handleSignal(SIGTERM);

        $this->assertTrue($callbackInvoked);
    }

    #[Test]
    public function handle_signal_without_pcntl(): void
    {
        $signal = 15;

        $this->logger->expects($this->once())
            ->method('warning');

        $this->handler->handleSignal($signal);
    }

    #[Test]
    public function reset_when_not_registered(): void
    {
        $this->handler->reset();
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function reset_restores_handlers(): void
    {
        $this->logger->method('info');
        $this->logger->method('error');

        $this->handler->register();
        $this->handler->reset();

        $result = $this->handler->handleError(E_WARNING, 'Test', __FILE__, __LINE__);
        $this->assertFalse($result);
    }

    #[Test]
    public function handle_error_with_non_fatal_error_types(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $errorTypes = [
            E_WARNING => 'E_WARNING',
            E_NOTICE => 'E_NOTICE',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        foreach ($errorTypes as $errno => $expectedType) {
            $this->handler->handleError($errno, "Test $expectedType", __FILE__, __LINE__);
        }

        error_reporting($oldReporting);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function handle_error_with_fatal_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_ERROR'));

        $this->handler->handleError(E_ERROR, 'Test E_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_error_with_core_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_CORE_ERROR'));

        $this->handler->handleError(E_CORE_ERROR, 'Test E_CORE_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_error_with_compile_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_COMPILE_ERROR'));

        $this->handler->handleError(E_COMPILE_ERROR, 'Test E_COMPILE_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_error_with_user_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_USER_ERROR'));

        $this->handler->handleError(E_USER_ERROR, 'Test E_USER_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_error_with_recoverable_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_RECOVERABLE_ERROR'));

        $this->handler->handleError(E_RECOVERABLE_ERROR, 'Test', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_error_with_parse_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_PARSE'));

        $this->handler->handleError(E_PARSE, 'Test', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_error_with_unknown_error_type(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => str_contains((string) $ctx['type'], 'UNKNOWN')));

        $this->handler->handleError(999999, 'Unknown error', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    #[Test]
    public function handle_signal_with_unknown_signal(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => str_contains((string) $ctx['name'], 'SIGNAL_')));

        $this->handler->handleSignal(999);
    }

    #[Test]
    public function handle_signal_with_callback_exception(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
        }

        $this->handler = new ErrorHandler(
            $this->logger,
            null,
            function (int $signal): void {
                throw new RuntimeException('Signal callback error');
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
    public function handle_signal_with_sigint(): void
    {
        if (!defined('SIGINT')) {
            $this->markTestSkipped('SIGINT not available');
        }

        $this->logger->expects($this->once())
            ->method('warning');
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Graceful shutdown initiated');

        $this->handler->handleSignal(SIGINT);
    }

    #[Test]
    public function handle_signal_with_sighup(): void
    {
        if (!defined('SIGHUP')) {
            $this->markTestSkipped('SIGHUP not available');
        }

        $this->logger->expects($this->once())
            ->method('warning');

        $this->handler->handleSignal(SIGHUP);
    }

    #[Test]
    public function get_signal_name_with_sigquit(): void
    {
        if (!defined('SIGQUIT')) {
            $this->markTestSkipped('SIGQUIT not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGQUIT'));

        $this->handler->handleSignal(SIGQUIT);
    }

    #[Test]
    public function get_signal_name_with_sigkill(): void
    {
        if (!defined('SIGKILL')) {
            $this->markTestSkipped('SIGKILL not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGKILL'));

        $this->handler->handleSignal(SIGKILL);
    }

    #[Test]
    public function get_signal_name_with_sigusr_1(): void
    {
        if (!defined('SIGUSR1')) {
            $this->markTestSkipped('SIGUSR1 not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGUSR1'));

        $this->handler->handleSignal(SIGUSR1);
    }

    #[Test]
    public function get_signal_name_with_sigusr_2(): void
    {
        if (!defined('SIGUSR2')) {
            $this->markTestSkipped('SIGUSR2 not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGUSR2'));

        $this->handler->handleSignal(SIGUSR2);
    }

    #[Test]
    public function constructor_with_all_parameters(): void
    {
        $onFatalError = function (array $error): void {};
        $onSignal = function (int $signal): void {};

        $handler = new ErrorHandler($this->logger, $onFatalError, $onSignal);

        $this->logger->method('info');
        $handler->register();
        $handler->reset();
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function handle_shutdown_resets_is_shutting_down_on_reset(): void
    {
        $this->logger->method('info');

        $this->handler->register();
        $this->handler->handleShutdown();
        $this->handler->reset();

        $this->handler->register();
        $this->handler->handleShutdown();

        $this->expectNotToPerformAssertions();
    }
}
