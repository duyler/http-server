<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketConfigException;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WebSocketConfigTest extends TestCase
{
    #[Test]
    public function creates_with_default_values(): void
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

    #[Test]
    public function creates_with_custom_values(): void
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

    #[Test]
    public function throws_on_invalid_max_message_size(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('maxMessageSize must be positive');

        new WebSocketConfig(maxMessageSize: 0);
    }

    #[Test]
    public function throws_on_invalid_max_frame_size(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('maxFrameSize must be positive');

        new WebSocketConfig(maxFrameSize: 0);
    }

    #[Test]
    public function throws_when_max_frame_exceeds_max_message(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('maxFrameSize cannot exceed maxMessageSize');

        new WebSocketConfig(maxMessageSize: 1024, maxFrameSize: 2048);
    }

    #[Test]
    public function throws_on_invalid_ping_interval(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('pingInterval must be positive');

        new WebSocketConfig(pingInterval: 0);
    }

    #[Test]
    public function throws_on_invalid_pong_timeout(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('pongTimeout must be positive');

        new WebSocketConfig(pongTimeout: 0);
    }

    #[Test]
    public function throws_on_invalid_handshake_timeout(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('handshakeTimeout must be positive');

        new WebSocketConfig(handshakeTimeout: 0);
    }

    #[Test]
    public function throws_on_invalid_close_timeout(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('closeTimeout must be positive');

        new WebSocketConfig(closeTimeout: 0);
    }

    #[Test]
    public function throws_on_invalid_write_buffer_size(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('writeBufferSize must be positive');

        new WebSocketConfig(writeBufferSize: 0);
    }

    #[Test]
    public function throws_on_non_string_allowed_origin(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('allowedOrigins must contain only strings');

        new WebSocketConfig(allowedOrigins: ['valid', 123], validateOrigin: false);
    }

    #[Test]
    public function empty_allowed_origins_by_default(): void
    {
        $config = new WebSocketConfig();

        $this->assertEmpty($config->allowedOrigins);
    }

    #[Test]
    public function throws_on_wildcard_with_validation(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('Wildcard origin with validation enabled is insecure');

        new WebSocketConfig(
            allowedOrigins: ['*'],
            validateOrigin: true,
        );
    }

    #[Test]
    public function accepts_wildcard_when_validation_disabled(): void
    {
        $config = new WebSocketConfig(
            allowedOrigins: ['*'],
            validateOrigin: false,
        );

        $this->assertSame(['*'], $config->allowedOrigins);
    }

    #[Test]
    public function accepts_specific_origins_with_validation(): void
    {
        $config = new WebSocketConfig(
            allowedOrigins: ['https://example.com', 'https://test.com'],
            validateOrigin: true,
        );

        $this->assertSame(['https://example.com', 'https://test.com'], $config->allowedOrigins);
    }

    #[Test]
    public function throws_on_non_string_sub_protocol(): void
    {
        $this->expectException(InvalidWebSocketConfigException::class);
        $this->expectExceptionMessage('subProtocols must contain only strings');

        new WebSocketConfig(subProtocols: ['valid', 456]);
    }
}
