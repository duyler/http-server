<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler\New;

use Duyler\HttpServer\ErrorHandler\ProductionErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ProductionErrorHandlerTest extends TestCase
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

    public function testRegisterLogsInfo(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        $this->handler->register();
    }

    public function testRegisterOnlyOnce(): void
    {
        $this->logger->expects($this->once())
            ->method('info');

        $this->handler->register();
        $this->handler->register();
    }

    public function testHandleErrorWithSuppressedReporting(): void
    {
        $oldReporting = error_reporting(0);

        $this->logger->expects($this->never())
            ->method('error');

        $result = $this->handler->handleError(E_WARNING, 'Test', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandleErrorLogsError(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_WARNING'));

        $result = $this->handler->handleError(E_WARNING, 'Test warning', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandleErrorForFatalError(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_ERROR'));

        $result = $this->handler->handleError(E_ERROR, 'Fatal error', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandleErrorForUserError(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_USER_ERROR'));

        $result = $this->handler->handleError(E_USER_ERROR, 'User error', __FILE__, __LINE__);

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandleException(): void
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

    public function testHandleShutdownWithoutError(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Server shutdown normally', $this->callback(fn($ctx) => is_array($ctx)));

        $this->handler->handleShutdown();
    }

    public function testHandleShutdownOnlyRunsOnce(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Server shutdown normally');

        $this->handler->handleShutdown();
        $this->handler->handleShutdown();
    }

    public function testHandleSignal(): void
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

    public function testHandleSignalWithCallback(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
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
        $this->logger->method('info');

        $this->handler->handleSignal(SIGTERM);

        $this->assertTrue($callbackInvoked);
    }

    public function testHandleSignalWithoutPcntl(): void
    {
        $signal = 15;

        $this->logger->expects($this->once())
            ->method('warning');

        $this->handler->handleSignal($signal);
    }

    public function testResetWhenNotRegistered(): void
    {
        $this->handler->reset();
        $this->assertTrue(true);
    }

    public function testResetRestoresHandlers(): void
    {
        $this->logger->method('info');
        $this->logger->method('error');

        $this->handler->register();
        $this->handler->reset();

        $result = $this->handler->handleError(E_WARNING, 'Test', __FILE__, __LINE__);
        $this->assertFalse($result);
    }

    public function testHandleErrorWithNonFatalErrorTypes(): void
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

        $this->assertTrue(true);
    }

    public function testHandleErrorWithFatalErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_ERROR'));

        $this->handler->handleError(E_ERROR, 'Test E_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleErrorWithCoreErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_CORE_ERROR'));

        $this->handler->handleError(E_CORE_ERROR, 'Test E_CORE_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleErrorWithCompileErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_COMPILE_ERROR'));

        $this->handler->handleError(E_COMPILE_ERROR, 'Test E_COMPILE_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleErrorWithUserErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_USER_ERROR'));

        $this->handler->handleError(E_USER_ERROR, 'Test E_USER_ERROR', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleErrorWithRecoverableErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_RECOVERABLE_ERROR'));

        $this->handler->handleError(E_RECOVERABLE_ERROR, 'Test', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleErrorWithParseErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => $ctx['type'] === 'E_PARSE'));

        $this->handler->handleError(E_PARSE, 'Test', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleErrorWithUnknownErrorType(): void
    {
        $oldReporting = error_reporting(E_ALL);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PHP Error', $this->callback(fn($ctx) => str_contains((string) $ctx['type'], 'UNKNOWN')));

        $this->handler->handleError(999999, 'Unknown error', __FILE__, __LINE__);

        error_reporting($oldReporting);
    }

    public function testHandleSignalWithUnknownSignal(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => str_contains((string) $ctx['name'], 'SIGNAL_')));

        $this->handler->handleSignal(999);
    }

    public function testHandleSignalWithCallbackException(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM not available');
        }

        $this->handler = new ProductionErrorHandler(
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

    public function testHandleSignalWithSigint(): void
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

    public function testHandleSignalWithSighup(): void
    {
        if (!defined('SIGHUP')) {
            $this->markTestSkipped('SIGHUP not available');
        }

        $this->logger->expects($this->once())
            ->method('warning');

        $this->handler->handleSignal(SIGHUP);
    }

    public function testGetSignalNameWithSigquit(): void
    {
        if (!defined('SIGQUIT')) {
            $this->markTestSkipped('SIGQUIT not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGQUIT'));

        $this->handler->handleSignal(SIGQUIT);
    }

    public function testGetSignalNameWithSigkill(): void
    {
        if (!defined('SIGKILL')) {
            $this->markTestSkipped('SIGKILL not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGKILL'));

        $this->handler->handleSignal(SIGKILL);
    }

    public function testGetSignalNameWithSigusr1(): void
    {
        if (!defined('SIGUSR1')) {
            $this->markTestSkipped('SIGUSR1 not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGUSR1'));

        $this->handler->handleSignal(SIGUSR1);
    }

    public function testGetSignalNameWithSigusr2(): void
    {
        if (!defined('SIGUSR2')) {
            $this->markTestSkipped('SIGUSR2 not available');
        }

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Received signal', $this->callback(fn($ctx) => $ctx['name'] === 'SIGUSR2'));

        $this->handler->handleSignal(SIGUSR2);
    }

    public function testConstructorWithAllParameters(): void
    {
        $onFatalError = function (array $error): void {};
        $onSignal = function (int $signal): void {};

        $handler = new ProductionErrorHandler($this->logger, $onFatalError, $onSignal);

        $this->logger->method('info');
        $handler->register();
        $handler->reset();
        $this->assertTrue(true);
    }

    public function testHandleShutdownResetsIsShuttingDownOnReset(): void
    {
        $this->logger->method('info');

        $this->handler->register();
        $this->handler->handleShutdown();
        $this->handler->reset();

        $this->handler->register();
        $this->handler->handleShutdown();

        $this->assertTrue(true);
    }
}
