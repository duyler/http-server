<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketConfigException;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use PHPUnit\Framework\TestCase;

class WebSocketConfigTest extends TestCase
{
    public function testCreatesWithDefaultValues(): void
    {
        $config = new WebSocketConfig();

        $this->assertSame(1048576, $config->maxMessageSize);
        $this->assertSame(65536, $config->maxFrameSize);
        $this->assertSame(30, $config->pingInterval);
        $this->assertSame(10, $config->pongTimeout);
        $this->assertTrue($config->autoPing);
        $this->assertSame(5, $config->handshakeTimeout);
        $this->assertSame(5, $config->closeTimeout);
        $this->assertSame([], $config->allowedOrigins);
        $this->assertTrue($config->validateOrigin);
        $this->assertTrue($config->requireMasking);
        $this->assertTrue($config->autoFragmentation);
        $this->assertSame(8192, $config->writeBufferSize);
        $this->assertFalse($config->enableCompression);
        $this->assertSame([], $config->subProtocols);
    }

    public function testCreatesWithCustomValues(): void
    {
        $config = new WebSocketConfig(
            maxMessageSize: 2097152,
            maxFrameSize: 131072,
            pingInterval: 60,
            pongTimeout: 20,
            autoPing: false,
            handshakeTimeout: 10,
            closeTimeout: 10,
            allowedOrigins: ['https://example.com'],
            validateOrigin: true,
            requireMasking: false,
            autoFragmentation: false,
            writeBufferSize: 16384,
            enableCompression: true,
            subProtocols: ['chat', 'superchat'],
        );

        $this->assertSame(2097152, $config->maxMessageSize);
        $this->assertSame(131072, $config->maxFrameSize);
        $this->assertSame(60, $config->pingInterval);
        $this->assertSame(20, $config->pongTimeout);
        $this->assertFalse($config->autoPing);
        $this->assertSame(10, $config->handshakeTimeout);
        $this->assertSame(10, $config->closeTimeout);
        $this->assertSame(['https://example.com'], $config->allowedOrigins);
        $this->assertTrue($config->validateOrigin);
        $this->assertFalse($config->requireMasking);
        $this->assertFalse($config->autoFragmentation);
        $this->assertSame(16384, $config->writeBufferSize);
        $this->assertTrue($config->enableCompression);
        $this->assertSame(['chat', 'superchat'], $config->subProtocols);
    }

    public function testThrowsOnInvalidMaxMessageSize(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('maxMessageSize must be positive');

        new WebSocketConfig(maxMessageSize: 0);
    }

    public function testThrowsOnInvalidMaxFrameSize(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('maxFrameSize must be positive');

        new WebSocketConfig(maxFrameSize: 0);
    }

    public function testThrowsWhenMaxFrameExceedsMaxMessage(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('maxFrameSize cannot exceed maxMessageSize');

        new WebSocketConfig(maxMessageSize: 1024, maxFrameSize: 2048);
    }

    public function testThrowsOnInvalidPingInterval(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('pingInterval must be positive');

        new WebSocketConfig(pingInterval: 0);
    }

    public function testThrowsOnInvalidPongTimeout(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('pongTimeout must be positive');

        new WebSocketConfig(pongTimeout: 0);
    }

    public function testThrowsOnInvalidHandshakeTimeout(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('handshakeTimeout must be positive');

        new WebSocketConfig(handshakeTimeout: 0);
    }

    public function testThrowsOnInvalidCloseTimeout(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('closeTimeout must be positive');

        new WebSocketConfig(closeTimeout: 0);
    }

    public function testThrowsOnInvalidWriteBufferSize(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('writeBufferSize must be positive');

        new WebSocketConfig(writeBufferSize: 0);
    }

    public function testThrowsOnNonStringAllowedOrigin(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('allowedOrigins must contain only strings');

        new WebSocketConfig(allowedOrigins: ['valid', 123], validateOrigin: false);
    }

    public function testEmptyAllowedOriginsByDefault(): void
    {
        $config = new WebSocketConfig();

        $this->assertEmpty($config->allowedOrigins);
    }

    public function testThrowsOnWildcardWithValidation(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('Wildcard origin with validation enabled is insecure');

        new WebSocketConfig(
            allowedOrigins: ['*'],
            validateOrigin: true,
        );
    }

    public function testAcceptsWildcardWhenValidationDisabled(): void
    {
        $config = new WebSocketConfig(
            allowedOrigins: ['*'],
            validateOrigin: false,
        );

        $this->assertSame(['*'], $config->allowedOrigins);
    }

    public function testAcceptsSpecificOriginsWithValidation(): void
    {
        $config = new WebSocketConfig(
            allowedOrigins: ['https://example.com', 'https://test.com'],
            validateOrigin: true,
        );

        $this->assertSame(['https://example.com', 'https://test.com'], $config->allowedOrigins);
    }

    public function testThrowsOnNonStringSubProtocol(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('subProtocols must contain only strings');

        new WebSocketConfig(subProtocols: ['valid', 456]);
    }
}
