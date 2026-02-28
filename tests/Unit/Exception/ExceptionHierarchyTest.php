<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Exception;

use Duyler\HttpServer\Exception\HttpServerException;
use Duyler\HttpServer\Exception\InvalidConfigException;
use Duyler\HttpServer\Exception\ParseException;
use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Exception\TimeoutException;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketConfigException;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketFrameException;
use Duyler\HttpServer\WorkerPool\Exception\IPCException;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExceptionHierarchyTest extends TestCase
{
    #[Test]
    public function all_exceptions_extend_http_server_exception(): void
    {
        $exceptions = [
            SocketException::class,
            ParseException::class,
            InvalidConfigException::class,
            TimeoutException::class,
            WorkerPoolException::class,
            IPCException::class,
            InvalidWebSocketConfigException::class,
            InvalidWebSocketFrameException::class,
        ];

        foreach ($exceptions as $exceptionClass) {
            $reflection = new ReflectionClass($exceptionClass);
            $parentClass = $reflection->getParentClass();

            $inheritsFromHttpServerException = false;
            while (null !== $parentClass) {
                if (HttpServerException::class === $parentClass->getName()) {
                    $inheritsFromHttpServerException = true;
                    break;
                }
                $parentClass = $parentClass->getParentClass();
            }

            $this->assertTrue(
                $inheritsFromHttpServerException,
                "{$exceptionClass} should inherit from HttpServerException",
            );
        }
    }

    #[Test]
    public function all_exceptions_have_unique_error_codes(): void
    {
        $exceptionClasses = [
            SocketException::class,
            ParseException::class,
            InvalidConfigException::class,
            TimeoutException::class,
            WorkerPoolException::class,
            IPCException::class,
            InvalidWebSocketConfigException::class,
            InvalidWebSocketFrameException::class,
        ];

        $errorCodes = [];
        foreach ($exceptionClasses as $exceptionClass) {
            $reflection = new ReflectionClass($exceptionClass);
            $exception = $reflection->newInstanceWithoutConstructor();

            $errorCode = $exception->getErrorCode();

            $this->assertNotEmpty($errorCode, "{$exceptionClass} should have an error code");

            if (array_key_exists($errorCode, $errorCodes)) {
                $this->fail("Error code '{$errorCode}' from {$exceptionClass} is not unique (already used by {$errorCodes[$errorCode]})");
            }

            $errorCodes[$errorCode] = $exceptionClass;
        }
    }
    #[Test]
    public function http_server_exception_is_abstract(): void
    {
        $reflection = new ReflectionClass(HttpServerException::class);

        $this->assertTrue($reflection->isAbstract(), 'HttpServerException should be abstract');
    }

    #[Test]
    public function http_server_exception_extends_exception(): void
    {
        $reflection = new ReflectionClass(HttpServerException::class);
        $parent = $reflection->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(Exception::class, $parent->getName());
    }

    #[Test]
    public function socket_exception_has_from_last_error_factory(): void
    {
        $reflection = new ReflectionClass(SocketException::class);

        $this->assertTrue($reflection->hasMethod('fromLastError'), 'SocketException should have fromLastError factory method');
    }

    #[Test]
    public function exception_has_context_parameter(): void
    {
        $exception = new SocketException('Test message', 0, null, ['key' => 'value']);

        $this->assertSame(['key' => 'value'], $exception->getContext());
    }

    #[Test]
    public function exception_context_defaults_to_empty_array(): void
    {
        $reflection = new ReflectionClass(SocketException::class);
        $constructor = $reflection->getConstructor();
        $contextParam = $constructor->getParameters()[3] ?? null;

        $this->assertNotNull($contextParam);
        $this->assertSame('context', $contextParam->getName());
        $this->assertTrue($contextParam->isDefaultValueAvailable());
        $this->assertSame([], $contextParam->getDefaultValue());
    }

    #[Test]
    public function get_error_code_returns_string(): void
    {
        $exception = new SocketException('Test message');

        $this->assertIsString($exception->getErrorCode());
    }

    #[Test]
    public function get_context_returns_array(): void
    {
        $exception = new SocketException('Test message');

        $this->assertIsArray($exception->getContext());
    }

    #[Test]
    public function invalid_web_socket_config_exception_extends_invalid_config_exception(): void
    {
        $reflection = new ReflectionClass(InvalidWebSocketConfigException::class);
        $parent = $reflection->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(InvalidConfigException::class, $parent->getName());
    }

    #[Test]
    public function catch_all_http_server_exceptions_works(): void
    {
        $exceptions = [
            new SocketException('socket'),
            new ParseException('parse'),
            new InvalidConfigException('config'),
            new TimeoutException('timeout'),
            new WorkerPoolException('worker'),
            new IPCException('ipc'),
            new InvalidWebSocketConfigException('websocket config'),
            new InvalidWebSocketFrameException('websocket frame'),
        ];

        foreach ($exceptions as $exception) {
            $caught = false;
            try {
                throw $exception;
            } catch (HttpServerException) {
                $caught = true;
            }

            $this->assertTrue($caught, $exception::class . ' should be catchable as HttpServerException');
        }
    }

    #[Test]
    public function socket_exception_error_code(): void
    {
        $reflection = new ReflectionClass(SocketException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('SOCKET_ERROR', $exception->getErrorCode());
    }

    #[Test]
    public function parse_exception_error_code(): void
    {
        $reflection = new ReflectionClass(ParseException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('PARSE_ERROR', $exception->getErrorCode());
    }

    #[Test]
    public function invalid_config_exception_error_code(): void
    {
        $reflection = new ReflectionClass(InvalidConfigException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('INVALID_CONFIG', $exception->getErrorCode());
    }

    #[Test]
    public function timeout_exception_error_code(): void
    {
        $reflection = new ReflectionClass(TimeoutException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('TIMEOUT_ERROR', $exception->getErrorCode());
    }

    #[Test]
    public function worker_pool_exception_error_code(): void
    {
        $reflection = new ReflectionClass(WorkerPoolException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('WORKER_POOL_ERROR', $exception->getErrorCode());
    }

    #[Test]
    public function ipc_exception_error_code(): void
    {
        $reflection = new ReflectionClass(IPCException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('IPC_ERROR', $exception->getErrorCode());
    }

    #[Test]
    public function invalid_web_socket_config_exception_error_code(): void
    {
        $reflection = new ReflectionClass(InvalidWebSocketConfigException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('INVALID_WEBSOCKET_CONFIG', $exception->getErrorCode());
    }

    #[Test]
    public function invalid_web_socket_frame_exception_error_code(): void
    {
        $reflection = new ReflectionClass(InvalidWebSocketFrameException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('INVALID_WEBSOCKET_FRAME', $exception->getErrorCode());
    }
}
