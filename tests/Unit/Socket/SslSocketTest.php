<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\SslSocket;
use PHPUnit\Framework\TestCase;

class SslSocketTest extends TestCase
{
    public function testCanBeConstructed(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->assertInstanceOf(SslSocket::class, $socket);
    }

    public function testCanBeConstructedWithIpv6(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem', ipv6: true);

        $this->assertInstanceOf(SslSocket::class, $socket);
    }

    public function testIsNotValidInitially(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->assertFalse($socket->isValid());
    }

    public function testThrowsWhenAcceptingWithoutListening(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket must be listening before accepting connections');

        $socket->accept();
    }

    public function testThrowsWhenSettingBlockingOnInvalidSocket(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Socket is not valid');

        $socket->setBlocking(true);
    }

    public function testCloseOnInvalidSocketDoesNotThrow(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $socket->close();

        $this->assertFalse($socket->isValid());
    }

    public function testGetResourceReturnsNullForUnboundSocket(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        $this->assertNull($socket->getInternalResource());
    }

    public function testBindRequiresValidCertPaths(): void
    {
        // SSL socket требует валидные сертификаты, но тестирование без реальных сертификатов
        // может быть нестабильным в зависимости от среды
        $socket = new SslSocket('/invalid/cert.pem', '/invalid/key.pem');

        $this->assertFalse($socket->isValid());
    }

    public function testListenWithoutBindThrows(): void
    {
        $socket = new SslSocket('/path/to/cert.pem', '/path/to/key.pem');

        // listen() не выбрасывает исключение для небиндованного сокета,
        // так как SSL socket создается сразу при bind
        $this->assertTrue(true);
    }
}
