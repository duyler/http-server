<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler;

use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class HandleExceptionTest extends TestCase
{
    use ErrorHandlerTestTrait;

    private mixed $originalStderr = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetErrorHandlerState();

        $this->originalStderr = fopen('php://stderr', 'w');
        $tempStream = fopen('php://temp', 'w+');

        stream_set_blocking($this->originalStderr, false);
        stream_set_blocking($tempStream, false);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->resetErrorHandlerState();

        if (null !== $this->originalStderr) {
            fclose($this->originalStderr);
            $this->originalStderr = null;
        }

        parent::tearDown();
    }

    public function testHandlesException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Uncaught exception',
                $this->callback(fn(array $context) => isset($context['exception'])
                    && isset($context['message'])
                    && isset($context['file'])
                    && isset($context['line'])),
            );

        ErrorHandler::register($logger);

        $exception = new RuntimeException('Test exception');

        ErrorHandler::handleException($exception);

        $this->assertTrue(true);
    }

    public function testLogsExceptionDetails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Uncaught exception',
                $this->callback(fn(array $context) => $context['exception'] === RuntimeException::class
                    && $context['message'] === 'Test exception message'
                    && isset($context['code'])
                    && isset($context['file'])
                    && isset($context['line'])
                    && isset($context['trace'])),
            );

        ErrorHandler::register($logger);

        $exception = new RuntimeException('Test exception message', 500);

        ErrorHandler::handleException($exception);
    }

    public function testHandlesExceptionWithoutLogger(): void
    {
        ErrorHandler::reset();

        $exception = new RuntimeException('Test exception');

        ErrorHandler::handleException($exception);

        $this->assertTrue(true);
    }

    public function testHandlesExceptionWithCode(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Uncaught exception',
                $this->callback(fn(array $context) => $context['code'] === 404),
            );

        ErrorHandler::register($logger);

        $exception = new RuntimeException('Not found', 404);

        ErrorHandler::handleException($exception);
    }

    public function testIncludesMemoryUsageInExceptionLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Uncaught exception',
                $this->callback(fn(array $context) => isset($context['memory_usage'])
                    && isset($context['memory_peak'])),
            );

        ErrorHandler::register($logger);

        $exception = new RuntimeException('Test exception');

        ErrorHandler::handleException($exception);
    }

    public function testHandlesDifferentExceptionTypes(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Uncaught exception',
                $this->callback(fn(array $context) => $context['exception'] === InvalidArgumentException::class),
            );

        ErrorHandler::register($logger);

        $exception = new InvalidArgumentException('Invalid argument');

        ErrorHandler::handleException($exception);
    }

    public function testHandlesExceptionWithPrevious(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('critical')
            ->with(
                'Uncaught exception',
                $this->callback(fn(array $context) => is_array($context)),
            );

        ErrorHandler::register($logger);

        $previous = new RuntimeException('Previous exception');
        $exception = new RuntimeException('Main exception', 0, $previous);

        ErrorHandler::handleException($exception);
    }
}
