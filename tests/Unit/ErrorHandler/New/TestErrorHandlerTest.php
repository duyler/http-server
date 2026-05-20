<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\ErrorHandler\New;

use Duyler\HttpServer\ErrorHandler\TestErrorHandler;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function register_sets_registered_flag(): void
    {
        $this->assertFalse($this->handler->isRegistered());

        $this->handler->register();

        $this->assertTrue($this->handler->isRegistered());
    }

    #[Test]
    public function handle_error_stores_error(): void
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

    #[Test]
    public function handle_error_stores_multiple_errors(): void
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

    #[Test]
    public function handle_exception_stores_exception(): void
    {
        $exception = new RuntimeException('Test exception');

        $this->handler->handleException($exception);

        $this->assertTrue($this->handler->hasExceptions());

        $exceptions = $this->handler->getExceptions();
        $this->assertCount(1, $exceptions);
        $this->assertSame($exception, $exceptions[0]);
    }

    #[Test]
    public function handle_exception_stores_multiple_exceptions(): void
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

    #[Test]
    public function handle_shutdown_does_nothing(): void
    {
        $this->handler->handleShutdown();
        $this->assertFalse($this->handler->hasErrors());
        $this->assertFalse($this->handler->hasExceptions());
    }

    #[Test]
    #[Group('pcntl')]
    public function handle_signal_does_nothing(): void
    {
        $this->handler->handleSignal(SIGTERM);
        $this->assertFalse($this->handler->hasErrors());
        $this->assertFalse($this->handler->hasExceptions());
    }

    #[Test]
    public function reset_clears_all_state(): void
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

    #[Test]
    public function has_errors_returns_false_initially(): void
    {
        $this->assertFalse($this->handler->hasErrors());
    }

    #[Test]
    public function has_exceptions_returns_false_initially(): void
    {
        $this->assertFalse($this->handler->hasExceptions());
    }

    #[Test]
    public function get_errors_returns_empty_array_initially(): void
    {
        $this->assertEmpty($this->handler->getErrors());
    }

    #[Test]
    public function get_exceptions_returns_empty_array_initially(): void
    {
        $this->assertEmpty($this->handler->getExceptions());
    }

    #[Test]
    public function multiple_resets(): void
    {
        $this->handler->register();
        $this->handler->handleError(E_WARNING, 'Test', __FILE__, 1);

        $this->handler->reset();
        $this->handler->reset();

        $this->assertFalse($this->handler->isRegistered());
        $this->assertFalse($this->handler->hasErrors());
    }
}
