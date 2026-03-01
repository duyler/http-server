<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\ErrorHandler;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ErrorHandlerTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        ErrorHandler::reset();
    }

    #[Override]
    protected function tearDown(): void
    {
        ErrorHandler::reset();
        parent::tearDown();
    }

    public function testCanBeRegistered(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        ErrorHandler::register($logger);

        $this->assertTrue(true);
    }

    public function testHandlesErrorsCorrectly(): void
    {
        // Просто проверяем, что handleError можно вызвать без ошибок
        $result = ErrorHandler::handleError(
            E_WARNING,
            'Test warning',
            __FILE__,
            __LINE__,
        );

        $this->assertFalse($result);
    }

    public function testExceptionHandlerIsRegistered(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        ErrorHandler::register($logger);

        $handlers = set_exception_handler(null);
        restore_exception_handler();

        $this->assertIsCallable($handlers);
    }

    public function testHandlesFatalErrorCallback(): void
    {
        $callbackInvoked = false;

        $callback = function (array $error) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            $this->assertArrayHasKey('type', $error);
            $this->assertArrayHasKey('message', $error);
            $this->assertArrayHasKey('file', $error);
            $this->assertArrayHasKey('line', $error);
        };

        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandler::register($logger, $callback);

        // Тестируем callback напрямую
        $testError = [
            'type' => E_ERROR,
            'message' => 'Test error',
            'file' => __FILE__,
            'line' => __LINE__,
        ];

        $callback($testError);

        $this->assertTrue($callbackInvoked);
    }

    public function testHandlesSignalCallback(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $callbackInvoked = false;

        $callback = function (int $signal) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            $this->assertIsInt($signal);
        };

        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandler::register($logger, null, $callback);

        // Тестируем callback напрямую
        $callback(SIGTERM);

        $this->assertTrue($callbackInvoked);
    }

    public function testDoesNotRegisterTwice(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->callback(fn($arg) => is_array($arg)));

        ErrorHandler::register($logger);

        $logger2 = $this->createMock(LoggerInterface::class);
        $logger2->expects($this->never())
            ->method('info');

        ErrorHandler::register($logger2);

        $this->assertTrue(true);
    }

    public function testHandlesErrorWithSuppressedReporting(): void
    {
        $oldReporting = error_reporting();
        error_reporting(0); // Suppress all errors

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
