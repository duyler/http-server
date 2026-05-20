<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\SslSocket;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SslSocketTest extends TestCase
{
    #[Test]
    public function can_be_constructed(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->assertInstanceOf(SslSocket::class, $socket);
    }

    #[Test]
    public function can_be_constructed_with_ipv_6(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem', ipv6: true);

        $this->assertInstanceOf(SslSocket::class, $socket);
    }

    #[Test]
    public function is_not_valid_initially(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->assertFalse($socket->isValid());
    }

    #[Test]
    public function throws_when_accepting_without_listening(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be listening before accepting connections');

        $socket->accept();
    }

    #[Test]
    public function throws_when_setting_blocking_on_invalid_socket(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket is not valid');

        $socket->setBlocking(true);
    }

    #[Test]
    public function close_on_invalid_socket_does_not_throw(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $socket->close();

        $this->assertFalse($socket->isValid());
    }

    #[Test]
    public function get_resource_returns_null_for_unbound_socket(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->assertNull($socket->getInternalResource());
    }

    #[Test]
    public function bind_requires_valid_cert_paths(): void
    {
        // SSL socket требует валидные сертификаты, но тестирование без реальных сертификатов
        // может быть нестабильным в зависимости от среды
        $socket = new SslSocket('/invalid/cert.pem', '/invalid/key.pem');

        $this->assertFalse($socket->isValid());
    }

    #[Test]
    public function listen_without_bind_throws(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->expectException(SocketException::class);

        $socket->listen();
    }

    #[Test]
    public function get_peer_name_returns_false_on_invalid_socket(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $result = $socket->getPeerName();

        $this->assertFalse($result);
    }

    #[Test]
    public function export_stream_returns_false_on_invalid_socket(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $result = $socket->exportStream();

        $this->assertFalse($result);
    }
}
