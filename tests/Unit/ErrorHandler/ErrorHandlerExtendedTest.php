<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler;

use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ErrorHandlerExtendedTest extends TestCase
{
    use ErrorHandlerTestTrait;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetErrorHandlerState();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->resetErrorHandlerState();
        parent::tearDown();
    }

    public function testRegisterReturnsEarlyWhenAlreadyRegistered(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info');

        ErrorHandler::register($logger);
        ErrorHandler::register($logger);
        ErrorHandler::register($logger);

        $this->assertTrue(true);
    }

    public function testHandleFatalErrorOutputsToStderr(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_ERROR'),
            );

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_ERROR,
            'Fatal error message',
            __FILE__,
            __LINE__,
        );

        $this->assertFalse($result);
    }

    public function testHandlesCoreError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_CORE_ERROR'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_CORE_ERROR,
            'Core error message',
            __FILE__,
            __LINE__,
        );
    }

    public function testHandlesCompileError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_COMPILE_ERROR'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_COMPILE_ERROR,
            'Compile error message',
            __FILE__,
            __LINE__,
        );
    }

    public function testHandlesCoreWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_CORE_WARNING'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_CORE_WARNING,
            'Core warning message',
            __FILE__,
            __LINE__,
        );
    }

    public function testHandlesCompileWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_COMPILE_WARNING'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_COMPILE_WARNING,
            'Compile warning message',
            __FILE__,
            __LINE__,
        );
    }

    public function testHandlesUserWarning(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_USER_WARNING'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_USER_WARNING,
            'User warning message',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesNotice(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_NOTICE'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_NOTICE,
            'Notice message',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesUserNotice(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_USER_NOTICE'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_USER_NOTICE,
            'User notice message',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesDeprecated(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_DEPRECATED'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_DEPRECATED,
            'Deprecated message',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesUserDeprecated(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_USER_DEPRECATED'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_USER_DEPRECATED,
            'User deprecated message',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesRecoverableError(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_RECOVERABLE_ERROR'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_RECOVERABLE_ERROR,
            'Recoverable error message',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesParseError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_PARSE'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_PARSE,
            'Parse error message',
            __FILE__,
            __LINE__,
        );
    }

    public function testHandlesUnknownErrorType(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => str_contains((string) $context['type'], 'UNKNOWN')),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            999999,
            'Unknown error message',
            __FILE__,
            __LINE__,
        );
    }

    public function testHandlesSignalSigkill(): void
    {
        if (!defined('SIGKILL')) {
            $this->markTestSkipped('SIGKILL not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['name'] === 'SIGKILL'),
            );

        ErrorHandler::register($logger);
        ErrorHandler::handleSignal(SIGKILL);
    }

    public function testResetRestoresErrorHandlers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        ErrorHandler::register($logger);

        ErrorHandler::reset();

        $result = ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        $this->assertFalse($result);
    }

    public function testResetWhenNotRegistered(): void
    {
        ErrorHandler::reset();

        $result = ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        $this->assertFalse($result);
    }
}
