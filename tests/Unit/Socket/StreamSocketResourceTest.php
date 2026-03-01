<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocketResource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;

class StreamSocketResourceTest extends TestCase
{
    public function testCreatesFromSocketObject(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertInstanceOf(Socket::class, $socket);

        $resource = new StreamSocketResource($socket);

        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    public function testThrowsOnInvalidResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid socket resource or Socket object');

        new StreamSocketResource('invalid');
    }

    public function testThrowsOnNullResource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamSocketResource(null);
    }

    public function testIsValidReturnsFalseAfterClose(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $this->assertTrue($resource->isValid());

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    public function testSetBlockingOnSocketObject(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->setBlocking(false);
        $this->assertTrue($resource->isValid());

        $resource->setBlocking(true);
        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    public function testThrowsOnSetBlockingInvalidSocket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot set blocking mode on invalid socket');

        $resource->setBlocking(false);
    }

    public function testReadReturnsFalseOnInvalidSocket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $result = $resource->read(1024);

        $this->assertFalse($result);
    }

    public function testWriteReturnsFalseOnInvalidSocket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $result = $resource->write('test');

        $this->assertFalse($result);
    }

    public function testReadReturnsFalseOnZeroLength(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $result = $resource->read(0);

        $this->assertFalse($result);

        $resource->close();
    }

    public function testGetInternalResourceReturnsSocket(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $internal = $resource->getInternalResource();

        $this->assertSame($socket, $internal);

        $resource->close();
    }

    public function testCloseIsIdempotent(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();
        $resource->close();
        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    public function testCloseWithCustomLogger(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $logger = $this->createStub(LoggerInterface::class);

        $resource = new StreamSocketResource($socket, $logger);

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    public function testCreatesFromStreamResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);

        $resource = new StreamSocketResource($stream);

        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    public function testIsValidReturnsFalseAfterStreamClose(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $this->assertTrue($resource->isValid());

        $resource->close();

        $this->assertFalse($resource->isValid());
    }

    public function testGetInternalResourceReturnsNullAfterClose(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $resource->close();

        $this->assertNull($resource->getInternalResource());
    }

    public function testSetBlockingOnStreamResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $resource->setBlocking(false);
        $this->assertTrue($resource->isValid());

        $resource->setBlocking(true);
        $this->assertTrue($resource->isValid());

        $resource->close();
    }

    public function testWriteToStreamResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        $resource = new StreamSocketResource($stream);

        $written = $resource->write('test data');

        $this->assertGreaterThan(0, $written);

        $resource->close();
    }

    public function testReadFromStreamResource(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'test data');
        rewind($stream);

        $resource = new StreamSocketResource($stream);

        $data = $resource->read(4);

        $this->assertSame('test', $data);

        $resource->close();
    }
    public function testReadReturnsFalseOnNegativeLength(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $resource = new StreamSocketResource($socket);

        $result = $resource->read(-1);

        $this->assertFalse($result);

        $resource->close();
    }
    public function testReadFromSocketObject(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        socket_write($server, 'test data');

        $resource = new StreamSocketResource($client);

        $data = $resource->read(4);

        $this->assertSame('test', $data);

        $resource->close();
        socket_close($server);
    }
    public function testWriteToSocketObject(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        [$server, $client] = $sockets;

        $resource = new StreamSocketResource($client);

        $written = $resource->write('test data');

        $this->assertGreaterThan(0, $written);

        $resource->close();
        socket_close($server);
    }

}
