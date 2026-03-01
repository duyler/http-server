<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler\New;

use Duyler\HttpServer\ErrorHandler\TestErrorHandler;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestErrorHandlerTest extends TestCase
{
    private TestErrorHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new TestErrorHandler();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->handler->reset();
        parent::tearDown();
    }

    public function testRegisterSetsRegisteredFlag(): void
    {
        $this->assertFalse($this->handler->isRegistered());

        $this->handler->register();

        $this->assertTrue($this->handler->isRegistered());
    }

    public function testHandleErrorStoresError(): void
    {
        $result = $this->handler->handleError(E_WARNING, 'Test warning', __FILE__, 42);

        $this->assertTrue($result);
        $this->assertTrue($this->handler->hasErrors());

        $errors = $this->handler->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame(E_WARNING, $errors[0]['type']);
        $this->assertSame('Test warning', $errors[0]['message']);
        $this->assertSame(__FILE__, $errors[0]['file']);
        $this->assertSame(42, $errors[0]['line']);
    }

    public function testHandleErrorStoresMultipleErrors(): void
    {
        $this->handler->handleError(E_WARNING, 'Warning 1', __FILE__, 1);
        $this->handler->handleError(E_NOTICE, 'Notice 1', __FILE__, 2);
        $this->handler->handleError(E_ERROR, 'Error 1', __FILE__, 3);

        $errors = $this->handler->getErrors();
        $this->assertCount(3, $errors);
        $this->assertSame(E_WARNING, $errors[0]['type']);
        $this->assertSame(E_NOTICE, $errors[1]['type']);
        $this->assertSame(E_ERROR, $errors[2]['type']);
    }

    public function testHandleExceptionStoresException(): void
    {
        $exception = new RuntimeException('Test exception');

        $this->handler->handleException($exception);

        $this->assertTrue($this->handler->hasExceptions());

        $exceptions = $this->handler->getExceptions();
        $this->assertCount(1, $exceptions);
        $this->assertSame($exception, $exceptions[0]);
    }

    public function testHandleExceptionStoresMultipleExceptions(): void
    {
        $exception1 = new RuntimeException('Exception 1');
        $exception2 = new RuntimeException('Exception 2');

        $this->handler->handleException($exception1);
        $this->handler->handleException($exception2);

        $exceptions = $this->handler->getExceptions();
        $this->assertCount(2, $exceptions);
        $this->assertSame($exception1, $exceptions[0]);
        $this->assertSame($exception2, $exceptions[1]);
    }

    public function testHandleShutdownDoesNothing(): void
    {
        $this->handler->handleShutdown();
        $this->assertFalse($this->handler->hasErrors());
        $this->assertFalse($this->handler->hasExceptions());
    }

    public function testHandleSignalDoesNothing(): void
    {
        $this->handler->handleSignal(SIGTERM);
        $this->assertFalse($this->handler->hasErrors());
        $this->assertFalse($this->handler->hasExceptions());
    }

    public function testResetClearsAllState(): void
    {
        $this->handler->register();
        $this->handler->handleError(E_WARNING, 'Test', __FILE__, 1);
        $this->handler->handleException(new RuntimeException('Test'));

        $this->assertTrue($this->handler->isRegistered());
        $this->assertTrue($this->handler->hasErrors());
        $this->assertTrue($this->handler->hasExceptions());

        $this->handler->reset();

        $this->assertFalse($this->handler->isRegistered());
        $this->assertFalse($this->handler->hasErrors());
        $this->assertFalse($this->handler->hasExceptions());
        $this->assertEmpty($this->handler->getErrors());
        $this->assertEmpty($this->handler->getExceptions());
    }

    public function testHasErrorsReturnsFalseInitially(): void
    {
        $this->assertFalse($this->handler->hasErrors());
    }

    public function testHasExceptionsReturnsFalseInitially(): void
    {
        $this->assertFalse($this->handler->hasExceptions());
    }

    public function testGetErrorsReturnsEmptyArrayInitially(): void
    {
        $this->assertEmpty($this->handler->getErrors());
    }

    public function testGetExceptionsReturnsEmptyArrayInitially(): void
    {
        $this->assertEmpty($this->handler->getExceptions());
    }

    public function testMultipleResets(): void
    {
        $this->handler->register();
        $this->handler->handleError(E_WARNING, 'Test', __FILE__, 1);

        $this->handler->reset();
        $this->handler->reset();

        $this->assertFalse($this->handler->isRegistered());
        $this->assertFalse($this->handler->hasErrors());
    }
}
