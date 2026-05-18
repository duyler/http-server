<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Security\AuditLoggerInterface;
use Duyler\HttpServer\WebSocket\Handshake;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class HandshakeTest extends TestCase
{
    public function testDetectsValidWebsocketRequest(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertTrue(Handshake::isWebSocketRequest($request));
    }

    public function testRejectsRequestWithoutUpgradeHeader(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    public function testRejectsRequestWithWrongUpgradeValue(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'http2',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    public function testRejectsRequestWithoutConnectionHeader(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    public function testRejectsRequestWithoutWebsocketKey(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    public function testRejectsRequestWithWrongVersion(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '12',
        ]);

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    public function testAcceptsConnectionWithMultipleValues(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Upgrade' => 'websocket',
            'Connection' => 'keep-alive, Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->assertTrue(Handshake::isWebSocketRequest($request));
    }

    public function testGeneratesCorrectAcceptKey(): void
    {
        $key = 'dGhlIHNhbXBsZSBub25jZQ==';
        $accept = Handshake::generateAccept($key);

        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $accept);
    }

    public function testCreatesHandshakeResponse(): void
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

    public function testIncludesProtocolInResponseWhenMatched(): void
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

    public function testExcludesProtocolWhenNoMatch(): void
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

    public function testValidatesOriginWhenEnabled(): void
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

    public function testRejectsInvalidOrigin(): void
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

    public function testAcceptsAnyOriginWithWildcardWhenValidationDisabled(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://any-domain.com',
        ]);

        $config = new WebSocketConfig(
            validateOrigin: false,
            allowedOrigins: ['*'],
        );

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    public function testRejectsAllOriginsByDefault(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://example.com',
        ]);

        $config = new WebSocketConfig(validateOrigin: true);

        $this->assertFalse(Handshake::validateOrigin($request, $config));
    }

    public function testSkipsOriginValidationWhenDisabled(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://any-domain.com',
        ]);

        $config = new WebSocketConfig(validateOrigin: false, allowedOrigins: ['*']);

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    public function testRejectsMissingOriginWhenValidationEnabled(): void
    {
        $request = new ServerRequest('GET', '/ws', []);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $this->assertFalse(Handshake::validateOrigin($request, $config));
    }

    public function testDetectsInsecureConfigWhenValidationDisabledWithWildcard(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: false,
            allowedOrigins: ['*'],
        );

        $this->assertTrue(Handshake::isInsecureConfig($config));
    }

    public function testDetectsInsecureConfigWhenValidationDisabledWithEmptyOrigins(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: false,
        );

        $this->assertTrue(Handshake::isInsecureConfig($config));
    }

    public function testDetectsSecureConfigWhenValidationEnabledWithSpecificOrigins(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $this->assertFalse(Handshake::isInsecureConfig($config));
    }

    public function testDetectsSecureConfigByDefault(): void
    {
        $config = new WebSocketConfig(validateOrigin: true);

        $this->assertFalse(Handshake::isInsecureConfig($config));
    }

    public function testAuditLoggerLogsWebSocketConnectionAccepted(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://example.com',
        ]);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logWebSocketConnection')
            ->with(
                $this->callback(fn(string $ip): bool => true),
                'https://example.com',
                true,
            );

        Handshake::validateOrigin($request, $config, $auditLogger);
    }

    public function testAuditLoggerLogsWebSocketConnectionRejected(): void
    {
        $request = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://evil.com',
        ]);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logWebSocketConnection')
            ->with(
                $this->callback(fn(string $ip): bool => true),
                'https://evil.com',
                false,
            );

        Handshake::validateOrigin($request, $config, $auditLogger);
    }

    public function testAuditLoggerLogsInvalidOriginWhenMissing(): void
    {
        $request = new ServerRequest('GET', '/ws', []);

        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logInvalidOrigin');

        Handshake::validateOrigin($request, $config, $auditLogger);
    }
}
