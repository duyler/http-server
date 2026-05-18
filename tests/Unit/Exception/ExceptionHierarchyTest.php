<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Exception;

use Duyler\HttpServer\Exception\HttpServerException;
use Duyler\HttpServer\Exception\InvalidConfigException;
use Duyler\HttpServer\Exception\MemoryLimitExceededException;
use Duyler\HttpServer\Exception\ParseException;
use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Exception\TimeoutException;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketConfigException;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketFrameException;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExceptionHierarchyTest extends TestCase
{
    public function testAllExceptionsExtendHttpServerException(): void
    {
        $exceptions = [
            SocketException::class,
            ParseException::class,
            InvalidConfigException::class,
            TimeoutException::class,
            InvalidWebSocketConfigException::class,
            InvalidWebSocketFrameException::class,
            MemoryLimitExceededException::class,
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

    public function testAllExceptionsHaveUniqueErrorCodes(): void
    {
        $exceptionClasses = [
            SocketException::class,
            ParseException::class,
            InvalidConfigException::class,
            TimeoutException::class,
            InvalidWebSocketConfigException::class,
            InvalidWebSocketFrameException::class,
            MemoryLimitExceededException::class,
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
    public function testHttpServerExceptionIsAbstract(): void
    {
        $reflection = new ReflectionClass(HttpServerException::class);

        $this->assertTrue($reflection->isAbstract(), 'HttpServerException should be abstract');
    }

    public function testHttpServerExceptionExtendsException(): void
    {
        $reflection = new ReflectionClass(HttpServerException::class);
        $parent = $reflection->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(Exception::class, $parent->getName());
    }

    public function testSocketExceptionHasFromLastErrorFactory(): void
    {
        $reflection = new ReflectionClass(SocketException::class);

        $this->assertTrue($reflection->hasMethod('fromLastError'), 'SocketException should have fromLastError factory method');
    }

    public function testExceptionHasContextParameter(): void
    {
        $exception = new SocketException('Test message', 0, null, ['key' => 'value']);

        $this->assertSame(['key' => 'value'], $exception->getContext());
    }

    public function testExceptionContextDefaultsToEmptyArray(): void
    {
        $reflection = new ReflectionClass(SocketException::class);
        $constructor = $reflection->getConstructor();
        $contextParam = $constructor->getParameters()[3] ?? null;

        $this->assertNotNull($contextParam);
        $this->assertSame('context', $contextParam->getName());
        $this->assertTrue($contextParam->isDefaultValueAvailable());
        $this->assertSame([], $contextParam->getDefaultValue());
    }

    public function testGetErrorCodeReturnsString(): void
    {
        $exception = new SocketException('Test message');

        $this->assertIsString($exception->getErrorCode());
    }

    public function testGetContextReturnsArray(): void
    {
        $exception = new SocketException('Test message');

        $this->assertIsArray($exception->getContext());
    }

    public function testInvalidWebSocketConfigExceptionExtendsInvalidConfigException(): void
    {
        $reflection = new ReflectionClass(InvalidWebSocketConfigException::class);
        $parent = $reflection->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(InvalidConfigException::class, $parent->getName());
    }

    public function testCatchAllHttpServerExceptionsWorks(): void
    {
        $exceptions = [
            new SocketException('socket'),
            new ParseException('parse'),
            new InvalidConfigException('config'),
            new TimeoutException('timeout'),
            new InvalidWebSocketConfigException('websocket config'),
            new InvalidWebSocketFrameException('websocket frame'),
            new MemoryLimitExceededException('memory'),
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

    public function testSocketExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(SocketException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('SOCKET_ERROR', $exception->getErrorCode());
    }

    public function testParseExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(ParseException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('PARSE_ERROR', $exception->getErrorCode());
    }

    public function testInvalidConfigExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(InvalidConfigException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('INVALID_CONFIG', $exception->getErrorCode());
    }

    public function testTimeoutExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(TimeoutException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('TIMEOUT_ERROR', $exception->getErrorCode());
    }

    public function testInvalidWebSocketConfigExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(InvalidWebSocketConfigException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('INVALID_WEBSOCKET_CONFIG', $exception->getErrorCode());
    }

    public function testInvalidWebSocketFrameExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(InvalidWebSocketFrameException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('INVALID_WEBSOCKET_FRAME', $exception->getErrorCode());
    }

    public function testMemoryLimitExceededExceptionErrorCode(): void
    {
        $reflection = new ReflectionClass(MemoryLimitExceededException::class);
        $exception = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('MEMORY_LIMIT_EXCEEDED', $exception->getErrorCode());
    }
}
