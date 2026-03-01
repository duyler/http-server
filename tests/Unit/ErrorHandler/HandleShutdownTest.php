<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler;

use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HandleShutdownTest extends TestCase
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

    public function testHandlesNormalShutdown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('info');

        ErrorHandler::register($logger);

        ErrorHandler::handleShutdown();

        $this->assertTrue(true);
    }

    public function testDoesNotCallCallbackOnNormalShutdown(): void
    {
        $callbackInvoked = false;

        $callback = function () use (&$callbackInvoked): void {
            $callbackInvoked = true;
        };

        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandler::register($logger, $callback);

        ErrorHandler::handleShutdown();

        $this->assertFalse($callbackInvoked);
    }

    public function testHandlesShutdownWithoutLogger(): void
    {
        ErrorHandler::reset();

        ErrorHandler::handleShutdown();

        $this->assertTrue(true);
    }

    public function testLogsMemoryUsageOnNormalShutdown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('info');

        ErrorHandler::register($logger);

        ErrorHandler::handleShutdown();
    }

    public function testOnlyRunsOnce(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('info');

        ErrorHandler::register($logger);

        ErrorHandler::handleShutdown();
        ErrorHandler::handleShutdown();
        ErrorHandler::handleShutdown();
    }

    public function testHandlesShutdownWithoutRegisteredHandler(): void
    {
        ErrorHandler::reset();

        ErrorHandler::handleShutdown();

        $this->assertTrue(true);
    }
}
