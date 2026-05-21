<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Frame;
use Duyler\HttpServer\WebSocket\Handshake;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
#[CoversClass(Frame::class)]
#[CoversClass(Handshake::class)]
class WebSocketCycleTest extends TestCase
{
    private ?Server $server = null;
    private int $port;
    private WebSocketServer $ws;

    #[Override]
    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);

        $wsConfig = new WebSocketConfig(
            validateOrigin: false,
            allowedOrigins: ['*'],
        );

        $this->ws = new WebSocketServer($wsConfig);
        $this->ws->on('message', function ($conn, $message): void {
            $payload = $message->getData();
            if (is_string($payload)) {
                $conn->send($payload);
            }
        });

        $this->server->attachWebSocket('/ws', $this->ws);
        $this->server->start();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function websocket_upgrade_handshake(): void
    {
        $client = $this->createClient();
        $key = base64_encode(random_bytes(16));

        $upgradeRequest = "GET /ws HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($client, $upgradeRequest);

        usleep(200000);

        $this->server->hasRequest();
        $requestData = $this->server->getRequest();

        if (null !== $requestData) {
            $response = new Response(200, [], '');
            $this->server->respond($requestData->respond($response));
        }

        usleep(100000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('101', $raw);
        $this->assertStringContainsString('Upgrade', $raw);
        $this->assertStringContainsString('websocket', $raw);
    }

    #[Test]
    public function text_frame_encode_decode_roundtrip(): void
    {
        $maskingKey = random_bytes(4);
        $payload = 'Hello WebSocket!';

        $frame = new Frame(
            opcode: Opcode::TEXT,
            payload: $payload,
            fin: true,
            masked: true,
            maskingKey: $maskingKey,
        );

        $encoded = $frame->encode();

        $decoded = Frame::decode($encoded);
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded->payload);
        $this->assertSame(Opcode::TEXT, $decoded->opcode);
        $this->assertTrue($decoded->fin);
        $this->assertTrue($decoded->masked);
    }

    #[Test]
    public function binary_frame_encode_decode_roundtrip(): void
    {
        $maskingKey = random_bytes(4);
        $payload = random_bytes(256);

        $frame = new Frame(
            opcode: Opcode::BINARY,
            payload: $payload,
            fin: true,
            masked: true,
            maskingKey: $maskingKey,
        );

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded->payload);
        $this->assertSame(Opcode::BINARY, $decoded->opcode);
    }

    #[Test]
    public function ping_frame_encode_decode(): void
    {
        $maskingKey = random_bytes(4);
        $payload = 'ping-data';

        $frame = new Frame(
            opcode: Opcode::PING,
            payload: $payload,
            fin: true,
            masked: true,
            maskingKey: $maskingKey,
        );

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(Opcode::PING, $decoded->opcode);
        $this->assertSame($payload, $decoded->payload);
    }

    #[Test]
    public function pong_frame_encode_decode(): void
    {
        $maskingKey = random_bytes(4);
        $payload = 'pong-data';

        $frame = new Frame(
            opcode: Opcode::PONG,
            payload: $payload,
            fin: true,
            masked: true,
            maskingKey: $maskingKey,
        );

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(Opcode::PONG, $decoded->opcode);
        $this->assertSame($payload, $decoded->payload);
    }

    #[Test]
    public function close_frame_encode_decode(): void
    {
        $maskingKey = random_bytes(4);
        $payload = pack('n', 1000) . 'Normal closure';

        $frame = new Frame(
            opcode: Opcode::CLOSE,
            payload: $payload,
            fin: true,
            masked: true,
            maskingKey: $maskingKey,
        );

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(Opcode::CLOSE, $decoded->opcode);
    }

    #[Test]
    public function unmasked_frame_encode_decode(): void
    {
        $payload = 'Unmasked text';

        $frame = new Frame(
            opcode: Opcode::TEXT,
            payload: $payload,
            fin: true,
        );

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded->payload);
        $this->assertFalse($decoded->masked);
    }

    #[Test]
    public function large_payload_frame_encode_decode(): void
    {
        $maskingKey = random_bytes(4);
        $payload = str_repeat('X', 70000);

        $frame = new Frame(
            opcode: Opcode::TEXT,
            payload: $payload,
            fin: true,
            masked: true,
            maskingKey: $maskingKey,
        );

        $encoded = $frame->encode();
        $decoded = Frame::decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame(strlen($payload), strlen($decoded->payload));
        $this->assertSame($payload, $decoded->payload);
    }

    #[Test]
    public function frame_decode_insufficient_data_returns_null(): void
    {
        $result = Frame::decode('x');

        $this->assertNull($result);
    }

    #[Test]
    public function frame_get_size_returns_correct_value(): void
    {
        $smallFrame = new Frame(Opcode::TEXT, 'hello', true);
        $this->assertSame(7, $smallFrame->getSize());

        $maskedFrame = new Frame(Opcode::TEXT, 'hello', true, true, random_bytes(4));
        $this->assertSame(11, $maskedFrame->getSize());

        $mediumPayload = str_repeat('A', 200);
        $mediumFrame = new Frame(Opcode::TEXT, $mediumPayload, true);
        $this->assertSame(204, $mediumFrame->getSize());
    }

    #[Test]
    public function handshake_generates_valid_accept_key(): void
    {
        $key = base64_encode(random_bytes(16));
        $accept = Handshake::generateAccept($key);

        $this->assertSame(28, strlen($accept));
        $this->assertTrue(base64_decode($accept, true) !== false);
    }

    #[Test]
    public function opcode_is_control_and_is_data(): void
    {
        $this->assertTrue(Opcode::CLOSE->isControl());
        $this->assertTrue(Opcode::PING->isControl());
        $this->assertTrue(Opcode::PONG->isControl());
        $this->assertFalse(Opcode::TEXT->isControl());
        $this->assertFalse(Opcode::BINARY->isControl());

        $this->assertTrue(Opcode::TEXT->isData());
        $this->assertTrue(Opcode::BINARY->isData());
        $this->assertFalse(Opcode::CLOSE->isData());
        $this->assertFalse(Opcode::PING->isData());
    }

    /**
     * @return resource
     */
    private function createClient()
    {
        $client = stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1.0,
        );

        if (false === $client) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

        stream_set_timeout($client, 5);

        return $client;
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}
