<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocketResource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

class StreamSocketResourceTest extends TestCase
{
    #[Test]
    public function creates_from_socket_object(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $resource = new StreamSocketResource($socket);

        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    #[Test]
    public function throws_on_invalid_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid socket resource or Socket object');

        new StreamSocketResource('invalid');
    }

    #[Test]
    public function throws_on_null_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamSocketResource(null);
    }

    #[Test]
    public function is_valid_returns_false_after_close(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $this->assertTrue($resource->isValid());

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    #[Test]
    public function set_blocking_on_socket_object(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->setBlocking(false);
        $this->assertTrue($resource->isValid());

        $resource->setBlocking(true);
        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    #[Test]
    public function throws_on_set_blocking_invalid_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot set blocking mode on invalid socket');

        $resource->setBlocking(false);
    }

    #[Test]
    public function read_returns_false_on_invalid_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $result = $resource->read(1024);

        $this->assertFalse($result);
    }

    #[Test]
    public function write_returns_false_on_invalid_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $result = $resource->write('test');

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_on_zero_length(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $result = $resource->read(0);

        $this->assertFalse($result);

        $resource->close();
    }

    #[Test]
    public function get_internal_resource_returns_socket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $internal = $resource->getInternalResource();

        $this->assertSame($socket, $internal);

        $resource->close();
    }
}
