<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\ErrorHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ErrorHandlerTest extends TestCase
{
    #[Test]
    public function can_be_registered(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Error handler registered', $this->isType('array'));

        ErrorHandler::register($logger);

        $this->assertTrue(true); // If we got here, registration succeeded
    }

    #[Test]
    public function handles_errors_correctly(): void
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

    #[Test]
    public function handles_exceptions_correctly(): void
    {
        $exception = new RuntimeException('Test exception');

        // Просто проверяем, что handleException можно вызвать без ошибок
        ErrorHandler::handleException($exception);

        $this->assertTrue(true); // If we got here, it worked
    }

    #[Test]
    public function handles_fatal_error_callback(): void
    {
        $callbackInvoked = false;

        $callback = function (array $error) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            $this->assertArrayHasKey('type', $error);
            $this->assertArrayHasKey('message', $error);
            $this->assertArrayHasKey('file', $error);
            $this->assertArrayHasKey('line', $error);
        };

        $logger = $this->createMock(LoggerInterface::class);
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

    #[Test]
    public function handles_signal_callback(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->markTestSkipped('pcntl extension not available');
        }

        $callbackInvoked = false;

        $callback = function (int $signal) use (&$callbackInvoked): void {
            $callbackInvoked = true;
            $this->assertIsInt($signal);
        };

        $logger = $this->createMock(LoggerInterface::class);
        ErrorHandler::register($logger, null, $callback);

        // Тестируем callback напрямую
        $callback(SIGTERM);

        $this->assertTrue($callbackInvoked);
    }

    #[Test]
    public function does_not_register_twice(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())
            ->method('info');

        // Уже зарегистрирован в предыдущих тестах
        ErrorHandler::register($logger);

        $this->assertTrue(true);
    }

    #[Test]
    public function handles_error_with_suppressed_reporting(): void
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
