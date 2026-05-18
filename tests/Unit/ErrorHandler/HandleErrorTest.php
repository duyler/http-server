<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler;

use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HandleErrorTest extends TestCase
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

    public function testHandlesWarning(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => isset($context['type'])
                    && $context['type'] === 'E_WARNING'
                    && isset($context['message'])
                    && isset($context['file'])
                    && isset($context['line'])),
            );

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
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

        $result = ErrorHandler::handleError(
            E_NOTICE,
            'Test notice',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesCoreWarning(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_CORE_WARNING'),
            );

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_CORE_WARNING,
            'Test core warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesCompileWarning(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_COMPILE_WARNING'),
            );

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_COMPILE_WARNING,
            'Test compile warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
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

        $result = ErrorHandler::handleError(
            E_USER_WARNING,
            'Test user warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
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

        $result = ErrorHandler::handleError(
            E_USER_NOTICE,
            'Test user notice',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesStrict(): void
    {
        // E_STRICT is deprecated in PHP 8.4, skip this test
        if (PHP_VERSION_ID >= 80400) {
            $this->markTestSkipped('E_STRICT is deprecated in PHP 8.4+');
        }

        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_STRICT'),
            );

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_STRICT,
            'Test strict',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesRecoverableError(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_RECOVERABLE_ERROR,
            'Test recoverable error',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesDeprecated(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_DEPRECATED,
            'Test deprecated',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesUserDeprecated(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_USER_DEPRECATED,
            'Test user deprecated',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesParse(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => $context['type'] === 'E_PARSE'),
            );

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_PARSE,
            'Test parse',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testHandlesUnknownErrorType(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error');

        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            99999,
            'Test unknown',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testReturnsFalseForSuppressedErrors(): void
    {
        $oldReporting = error_reporting();
        error_reporting(0);

        $result = ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }

    public function testIncludesMemoryUsageInLog(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'PHP Error',
                $this->callback(fn(array $context) => isset($context['memory_usage'])
                    && isset($context['memory_peak'])),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);
    }

    public function testHandlesErrorWithoutRegisteredErrorHandler(): void
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

    public function testHandlesErrorWithDifferentErrorLevels(): void
    {
        $oldReporting = error_reporting();
        error_reporting(E_ALL);

        $logger = $this->createMock(LoggerInterface::class);
        ErrorHandler::register($logger);

        $result = ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        error_reporting($oldReporting);

        $this->assertFalse($result);
    }
}
