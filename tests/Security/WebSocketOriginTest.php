<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Security;

use Duyler\HttpServer\WebSocket\Handshake;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Handshake::class)]
class WebSocketOriginTest extends TestCase
{
    #[Test]
    public function allowed_origin_passes_validation(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com', 'https://trusted.com'],
        );

        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Origin' => 'https://example.com',
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '13',
            ],
        );

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function disallowed_origin_fails_validation(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Origin' => 'https://evil.com',
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '13',
            ],
        );

        $this->assertFalse(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function empty_origin_rejected(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );

        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '13',
            ],
        );

        $this->assertFalse(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function validation_disabled_allows_all_origins(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: false,
            allowedOrigins: ['*'],
        );

        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Origin' => 'https://any-domain.com',
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '13',
            ],
        );

        $this->assertTrue(Handshake::validateOrigin($request, $config));
    }

    #[Test]
    public function multiple_origins_validated_correctly(): void
    {
        $config = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://app1.com', 'https://app2.com', 'https://app3.com'],
        );

        $validRequest = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://app2.com',
        ]);
        $this->assertTrue(Handshake::validateOrigin($validRequest, $config));

        $invalidRequest = new ServerRequest('GET', '/ws', [
            'Origin' => 'https://app4.com',
        ]);
        $this->assertFalse(Handshake::validateOrigin($invalidRequest, $config));
    }

    #[Test]
    public function is_web_socket_request_detects_valid_upgrade(): void
    {
        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '13',
            ],
        );

        $this->assertTrue(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function is_web_socket_request_rejects_missing_upgrade(): void
    {
        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '13',
            ],
        );

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function is_web_socket_request_rejects_wrong_version(): void
    {
        $request = new ServerRequest(
            'GET',
            '/ws',
            [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
                'Sec-WebSocket-Version' => '12',
            ],
        );

        $this->assertFalse(Handshake::isWebSocketRequest($request));
    }

    #[Test]
    public function generate_accept_produces_valid_base64(): void
    {
        $key = base64_encode(random_bytes(16));
        $accept = Handshake::generateAccept($key);

        $decoded = base64_decode($accept, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(20, strlen($decoded));
    }

    #[Test]
    public function is_insecure_config_detects_unsafe_settings(): void
    {
        $insecureConfig = new WebSocketConfig(
            validateOrigin: false,
            allowedOrigins: ['*'],
        );
        $this->assertTrue(Handshake::isInsecureConfig($insecureConfig));

        $secureConfig = new WebSocketConfig(
            validateOrigin: true,
            allowedOrigins: ['https://example.com'],
        );
        $this->assertFalse(Handshake::isInsecureConfig($secureConfig));
    }
}
