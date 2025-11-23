<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Handshake;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HandshakeTest extends TestCase
{
    #[Test]
    public function detects_valid_websocket_request(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertTrue(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function rejects_request_without_upgrade_header(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function rejects_request_with_wrong_upgrade_value(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'http2',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function rejects_request_without_connection_header(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function rejects_request_without_websocket_key(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function rejects_request_with_wrong_version(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '12',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function accepts_connection_with_multiple_values(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'keep-alive, Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertTrue(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function generates_correct_accept_key(): void
    {
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $accept = Handshake::generateAccept($key);

        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $accept);
    }

    #[Test]
    public function creates_handshake_response(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $config = new WebSocketConfig();
        $response = Handshake::createResponse($request, $config);

        $this->assertStringContainsString('HTTP/1.1 101 Switching Protocols', $response);
        $this->assertStringContainsString('Upgrade: websocket', $response);
        $this->assertStringContainsString('Connection: Upgrade', $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    #[Test]
    public function includes_protocol_in_response_when_matched(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => 'chat, superchat',
        ]);

        $config = new WebSocketConfig(subProtocols: ['superchat', 'otherchat']);
        $response = Handshake::createResponse($request, $config);

        $this->assertStringContainsString('Sec-WebSocket-Protocol: superchat', $response);
    }

    #[Test]
    public function excludes_protocol_when_no_match(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Protocol' => 'chat',
        ]);

        $config = new WebSocketConfig(subProtocols: ['superchat']);
        $response = Handshake::createResponse($request, $config);

        $this->assertStringNotContainsString('Sec-WebSocket-Protocol:', $response);
    }

    #[Test]
    public function validates_origin_when_enabled(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://example.com',
        ]);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com', 'https://test.com'],
        );

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function rejects_invalid_origin(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://evil.com',
        ]);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $this->assertFalse(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function accepts_any_origin_with_wildcard(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://any-domain.com',
        ]);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['*'],
        );

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function skips_origin_validation_when_disabled(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://any-domain.com',
        ]);

        $config = new WebSocketConfig(validateOrigin: false);

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function rejects_missing_origin_when_validation_enabled(): void
    {
        $request = new ServerRequest('GET', '/ws', []);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $this->assertFalse(Handshake::validateOrigin($request, $config));
    }
}
