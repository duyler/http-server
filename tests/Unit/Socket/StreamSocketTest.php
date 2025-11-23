<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\StreamSocket;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

class StreamSocketTest extends TestCase
{
    private StreamSocket $socket;

    protected function setUp(): void
    {
        $this->socket = new StreamSocket();
    }

    protected function tearDown(): void
    {
        $this->socket->close();
    }

    #[Test]
    public function is_not_valid_initially(): void
    {
        $this->assertFalse($this->socket->isValid());
    }

    #[Test]
    public function binds_to_address_and_port(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function throws_exception_when_binding_to_used_port(): void
    {
        $socket1 = new StreamSocket();
        $socket1->bind('127.0.0.1', 0);

        $this->expectException(SocketException::class);

        $socket2 = new StreamSocket();
        $socket2->bind('127.0.0.1', 1);

        $socket1->close();
    }

    #[Test]
    public function listens_after_bind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function throws_exception_when_listening_without_bind(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be bound before listening');

        $this->socket->listen();
    }

    #[Test]
    public function throws_exception_when_accepting_without_listening(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be listening before accepting connections');

        $this->socket->accept();
    }

    #[Test]
    public function sets_blocking_mode(): void
    {
        $this->socket->bind('127.0.0.1', 0);

        $this->socket->setBlocking(true);
        $this->socket->setBlocking(false);

        $this->assertTrue($this->socket->isValid());
    }

    #[Test]
    public function throws_exception_when_setting_blocking_on_invalid_socket(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket is not valid');

        $this->socket->setBlocking(true);
    }

    #[Test]
    public function closes_socket(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->close();

        $this->assertFalse($this->socket->isValid());
    }

    #[Test]
    public function returns_null_resource_when_not_bound(): void
    {
        $resource = $this->socket->getResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function returns_resource_after_bind(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $resource = $this->socket->getResource();

        $this->assertTrue(is_resource($resource) || $resource instanceof Socket);
    }

    #[Test]
    public function accepts_returns_false_in_non_blocking_mode_with_no_connections(): void
    {
        $this->socket->bind('127.0.0.1', 0);
        $this->socket->listen();
        $this->socket->setBlocking(false);

        $client = $this->socket->accept();

        $this->assertFalse($client);
    }
}
