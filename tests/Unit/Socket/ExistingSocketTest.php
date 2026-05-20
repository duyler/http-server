<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\ExistingSocket;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

#[CoversClass(ExistingSocket::class)]
class ExistingSocketTest extends TestCase
{
    private ?Socket $socket = null;
    private ?ExistingSocket $existingSocket = null;

    #[Override]
    protected function setUp(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $socket) {
            $this->markTestSkipped('Failed to create test socket');
        }
        $this->socket = $socket;
        $this->existingSocket = new ExistingSocket($this->socket);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->existingSocket && $this->existingSocket->isValid()) {
            $this->existingSocket->close();
        }
        $this->existingSocket = null;
        $this->socket = null;
        parent::tearDown();
    }

    #[Test]
    public function bindThrowsException(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot bind existing socket');

        $this->existingSocket->bind('127.0.0.1', 8080);
    }

    #[Test]
    public function listenThrowsException(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot listen on existing socket');

        $this->existingSocket->listen(511);
    }

    #[Test]
    public function isValidReturnsTrueInitially(): void
    {
        $this->assertTrue($this->existingSocket->isValid());
    }

    #[Test]
    public function isValidReturnsFalseAfterClose(): void
    {
        $this->existingSocket->close();

        $this->assertFalse($this->existingSocket->isValid());
    }

    #[Test]
    public function closeSetsClosedFlag(): void
    {
        $this->existingSocket->close();

        $this->assertFalse($this->existingSocket->isValid());
    }

    #[Test]
    public function readReturnsFalseWhenClosed(): void
    {
        $this->existingSocket->close();

        $result = $this->existingSocket->read(1024);

        $this->assertFalse($result);
    }

    #[Test]
    public function writeReturnsFalseWhenClosed(): void
    {
        $this->existingSocket->close();

        $result = $this->existingSocket->write('test');

        $this->assertFalse($result);
    }

    #[Test]
    public function acceptReturnsFalseWhenClosed(): void
    {
        $this->existingSocket->close();

        $result = $this->existingSocket->accept();

        $this->assertFalse($result);
    }

    #[Test]
    public function getInternalResourceReturnsSocket(): void
    {
        $result = $this->existingSocket->getInternalResource();

        $this->assertSame($this->socket, $result);
    }

    #[Test]
    public function setBlockingDoesNotThrow(): void
    {
        $this->existingSocket->setBlocking(true);
        $this->existingSocket->setBlocking(false);

        $this->assertTrue($this->existingSocket->isValid());
    }

    #[Test]
    public function setBlockingDoesNothingWhenClosed(): void
    {
        $this->existingSocket->close();

        $this->existingSocket->setBlocking(true);

        $this->assertFalse($this->existingSocket->isValid());
    }

    #[Test]
    public function acceptReturnsFalseOnUnboundSocket(): void
    {
        $previousHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousHandler): bool {
            if (str_contains($errstr, 'Invalid argument')) {
                return true;
            }
            return false !== $previousHandler && $previousHandler($errno, $errstr, $errfile, $errline);
        });

        try {
            $result = $this->existingSocket->accept();

            $this->assertFalse($result);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function readReturnsFalseOnUnconnectedSocket(): void
    {
        $previousHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousHandler): bool {
            if (str_contains($errstr, 'Transport endpoint is not connected')) {
                return true;
            }
            return false !== $previousHandler && $previousHandler($errno, $errstr, $errfile, $errline);
        });

        try {
            $result = $this->existingSocket->read(1024);

            $this->assertFalse($result);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function writeReturnsFalseOnUnconnectedSocket(): void
    {
        $previousHandler = set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousHandler): bool {
            if (str_contains($errstr, 'Broken pipe')) {
                return true;
            }
            return false !== $previousHandler && $previousHandler($errno, $errstr, $errfile, $errline);
        });

        try {
            $result = $this->existingSocket->write('test data');

            $this->assertFalse($result);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function closeCanBeCalledMultipleTimes(): void
    {
        $this->existingSocket->close();
        $this->existingSocket->close();

        $this->assertFalse($this->existingSocket->isValid());
    }

    #[Test]
    public function readReturnsDataWhenAvailable(): void
    {
        $pair = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        if (false === $result) {
            $this->markTestSkipped('Failed to create socket pair');
        }

        [$server, $client] = $pair;

        socket_write($client, 'test data');

        $existingSocket = new ExistingSocket($server);

        $data = $existingSocket->read(1024);

        $this->assertSame('test data', $data);

        $existingSocket->close();
        socket_close($client);
    }

    #[Test]
    public function writeReturnsBytesWritten(): void
    {
        $pair = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        if (false === $result) {
            $this->markTestSkipped('Failed to create socket pair');
        }

        [$server, $client] = $pair;

        $existingSocket = new ExistingSocket($server);

        $written = $existingSocket->write('test data');

        $this->assertSame(9, $written);

        $existingSocket->close();
        socket_close($client);
    }

    #[Test]
    public function acceptReturnsSocketResourceWhenConnectionAvailable(): void
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $serverSocket) {
            $this->markTestSkipped('Failed to create server socket');
        }

        socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($serverSocket, '127.0.0.1', 19001);
        socket_listen($serverSocket);
        socket_set_nonblock($serverSocket);

        $existingSocket = new ExistingSocket($serverSocket);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($clientSocket);
        $previousErrorReporting = error_reporting(0);
        socket_connect($clientSocket, '127.0.0.1', 19001);
        error_reporting($previousErrorReporting);

        usleep(10000);

        $accepted = $existingSocket->accept();

        $this->assertInstanceOf(\Duyler\HttpServer\Socket\SocketResourceInterface::class, $accepted);

        $existingSocket->close();
        socket_close($clientSocket);
        if ($accepted instanceof \Duyler\HttpServer\Socket\SocketResourceInterface) {
            $accepted->close();
        }
    }

    #[Test]
    public function getPeerNameReturnsFalseOnClosedSocket(): void
    {
        $this->existingSocket->close();

        $result = $this->existingSocket->getPeerName();

        $this->assertFalse($result);
    }

    #[Test]
    public function getPeerNameReturnsFalseOnUnconnectedSocket(): void
    {
        $previousHandler = set_error_handler(static fn(): bool => true);
        $result = $this->existingSocket->getPeerName();
        restore_error_handler();

        $this->assertFalse($result);
    }

    #[Test]
    public function getPeerNameReturnsPeerInfoOnConnectedSocket(): void
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($serverSocket, '127.0.0.1', 0);
        socket_listen($serverSocket, 1);

        $address = '';
        $port = 0;
        socket_getsockname($serverSocket, $address, $port);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_nonblock($clientSocket);
        $previousErrorReporting = error_reporting(0);
        socket_connect($clientSocket, '127.0.0.1', $port);
        error_reporting($previousErrorReporting);

        usleep(10000);

        $accepted = socket_accept($serverSocket);

        $existingSocket = new ExistingSocket($accepted);

        $result = $existingSocket->getPeerName();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ip', $result);
        $this->assertArrayHasKey('port', $result);
        $this->assertSame('127.0.0.1', $result['ip']);
        $this->assertIsInt($result['port']);

        $existingSocket->close();
        socket_close($clientSocket);
        socket_close($serverSocket);
    }

    #[Test]
    public function exportStreamReturnsFalseOnClosedSocket(): void
    {
        $this->existingSocket->close();

        $result = $this->existingSocket->exportStream();

        $this->assertFalse($result);
    }

    #[Test]
    public function exportStreamReturnsResourceOnValidSocket(): void
    {
        $pair = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        if (false === $result) {
            $this->markTestSkipped('Failed to create socket pair');
        }

        [$server, $client] = $pair;

        socket_set_nonblock($server);

        $existingSocket = new ExistingSocket($server);

        $stream = $existingSocket->exportStream();

        $this->assertIsResource($stream);

        $existingSocket->close();
        socket_close($client);
    }
}
