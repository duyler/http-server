<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler;

use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\Tests\Support\ErrorHandlerTestTrait;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class HandleSignalTest extends TestCase
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

    public function testHandlesSigterm(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['signal'] === SIGTERM
                    && $context['name'] === 'SIGTERM'),
            );

        $logger->expects($this->exactly(2))
            ->method('info');

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGTERM);

        $this->assertTrue(true);
    }

    public function testHandlesSigint(): void
    {
        if (!defined('SIGINT')) {
            $this->markTestSkipped('SIGINT constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['signal'] === SIGINT
                    && $context['name'] === 'SIGINT'),
            );

        $logger->expects($this->exactly(2))
            ->method('info');

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGINT);

        $this->assertTrue(true);
    }

    public function testHandlesSighup(): void
    {
        if (!defined('SIGHUP')) {
            $this->markTestSkipped('SIGHUP constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['signal'] === SIGHUP
                    && $context['name'] === 'SIGHUP'),
            );

        $logger->expects($this->once())
            ->method('info');

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGHUP);

        $this->assertTrue(true);
    }

    public function testCallsSignalCallback(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM constant not available');
        }

        $callbackInvoked = false;
        $receivedSignal = 0;

        $callback = function (int $signal) use (&$callbackInvoked, &$receivedSignal): void {
            $callbackInvoked = true;
            $receivedSignal = $signal;
        };

        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandler::register($logger, null, $callback);

        ErrorHandler::handleSignal(SIGTERM);

        $this->assertTrue($callbackInvoked);
        $this->assertSame(SIGTERM, $receivedSignal);
    }

    public function testDoesNotCallSignalCallbackForSighup(): void
    {
        if (!defined('SIGHUP')) {
            $this->markTestSkipped('SIGHUP constant not available');
        }

        $callbackInvoked = false;

        $callback = function () use (&$callbackInvoked): void {
            $callbackInvoked = true;
        };

        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandler::register($logger, null, $callback);

        ErrorHandler::handleSignal(SIGHUP);

        $this->assertFalse($callbackInvoked);
    }

    public function testLogsMemoryUsageOnSignal(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => isset($context['memory_usage'])),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGTERM);
    }

    public function testHandlesUnknownSignal(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => str_contains((string) $context['name'], 'SIGNAL_')),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(999);

        $this->assertTrue(true);
    }

    public function testHandlesSignalWithoutLogger(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM constant not available');
        }

        ErrorHandler::reset();

        ErrorHandler::handleSignal(SIGTERM);

        $this->assertTrue(true);
    }

    public function testHandlesSignalWithoutCallback(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM constant not available');
        }

        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGTERM);

        $this->assertTrue(true);
    }

    public function testHandlesExceptionInSignalCallback(): void
    {
        if (!defined('SIGTERM')) {
            $this->markTestSkipped('SIGTERM constant not available');
        }

        $callback = function (): void {
            throw new RuntimeException('Signal callback error');
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Error in signal callback',
                $this->callback(fn(array $context) => isset($context['error'])),
            );

        ErrorHandler::register($logger, null, $callback);

        ErrorHandler::handleSignal(SIGTERM);

        $this->assertTrue(true);
    }

    public function testHandlesSigquit(): void
    {
        if (!defined('SIGQUIT')) {
            $this->markTestSkipped('SIGQUIT constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['name'] === 'SIGQUIT'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGQUIT);

        $this->assertTrue(true);
    }

    public function testHandlesSigusr1(): void
    {
        if (!defined('SIGUSR1')) {
            $this->markTestSkipped('SIGUSR1 constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['name'] === 'SIGUSR1'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGUSR1);

        $this->assertTrue(true);
    }

    public function testHandlesSigusr2(): void
    {
        if (!defined('SIGUSR2')) {
            $this->markTestSkipped('SIGUSR2 constant not available');
        }

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Received signal',
                $this->callback(fn(array $context) => $context['name'] === 'SIGUSR2'),
            );

        ErrorHandler::register($logger);

        ErrorHandler::handleSignal(SIGUSR2);

        $this->assertTrue(true);
    }
}
