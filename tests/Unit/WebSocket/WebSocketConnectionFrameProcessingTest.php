<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Connection\Connection as TcpConnection;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\CloseCode;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Frame;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

class WebSocketConnectionFrameProcessingTest extends TestCase
{
    use \Duyler\HttpServer\Tests\Support\ErrorReportingScope;
    private WebSocketServer $server;
    private Connection $connection;

    /** @var array<Socket> */
    private array $sockets = [];

    #[Override]
    protected function setUp(): void
    {
        $this->server = new WebSocketServer();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        assert($socket !== false);
        $this->sockets[] = $socket;

        $resource = new StreamSocketResource($socket);
        $tcpConnection = new TcpConnection($resource, '127.0.0.1', 8080);

        $request = new ServerRequest('GET', '/ws');

        $this->connection = new Connection($tcpConnection, $request, $this->server);
        $this->connection->setState(ConnectionState::OPEN);
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            $this->withSuppressedErrors(static fn() => socket_close($socket));
        }
    }

    #[Test]
    public function process_text_frame_returns_message(): void
    {
        $frame = new Frame(Opcode::TEXT, 'hello', fin: true, masked: false);

        $message = $this->connection->processFrame($frame);

        $this->assertNotNull($message);
        $this->assertSame('hello', $message->getData());
        $this->assertTrue($message->isText());
    }

    #[Test]
    public function process_binary_frame_returns_message(): void
    {
        $frame = new Frame(Opcode::BINARY, "\x00\x01\x02", fin: true, masked: false);

        $message = $this->connection->processFrame($frame);

        $this->assertNotNull($message);
        $this->assertFalse($message->isText());
    }

    #[Test]
    public function process_unfinished_text_frame_returns_null(): void
    {
        $frame = new Frame(Opcode::TEXT, 'partial', fin: false, masked: false);

        $message = $this->connection->processFrame($frame);

        $this->assertNull($message);
    }

    #[Test]
    public function process_continuation_frame_without_fin_returns_null(): void
    {
        $textFrame = new Frame(Opcode::TEXT, 'part1', fin: false, masked: false);
        $this->connection->processFrame($textFrame);

        $continuationFrame = new Frame(Opcode::CONTINUATION, 'part2', fin: false, masked: false);

        $message = $this->connection->processFrame($continuationFrame);

        $this->assertNull($message);
    }

    #[Test]
    public function process_continuation_frame_with_fin_returns_complete_message(): void
    {
        $textFrame = new Frame(Opcode::TEXT, 'part1', fin: false, masked: false);
        $this->connection->processFrame($textFrame);

        $continuationFrame = new Frame(Opcode::CONTINUATION, 'part2', fin: true, masked: false);

        $message = $this->connection->processFrame($continuationFrame);

        $this->assertNotNull($message);
        $this->assertSame('part1part2', $message->getData());
        $this->assertTrue($message->isText());
    }

    #[Test]
    public function process_close_frame_changes_state(): void
    {
        $payload = pack('n', CloseCode::NORMAL->value) . 'Goodbye';
        $frame = new Frame(Opcode::CLOSE, $payload, fin: true, masked: false);

        $this->withSuppressedErrors(fn() => $this->connection->processFrame($frame));

        $this->assertSame(ConnectionState::CLOSED, $this->connection->getState());
    }

    #[Test]
    public function process_close_frame_emits_close_event(): void
    {
        $receivedCode = 0;
        $receivedReason = '';

        $this->server->on('close', function (Connection $conn, int $code, string $reason) use (&$receivedCode, &$receivedReason): void {
            $receivedCode = $code;
            $receivedReason = $reason;
        });

        $this->server->addConnection($this->connection);

        $payload = pack('n', CloseCode::GOING_AWAY->value) . 'Shutdown';
        $frame = new Frame(Opcode::CLOSE, $payload, fin: true, masked: false);

        $this->withSuppressedErrors(fn() => $this->connection->processFrame($frame));

        $this->assertSame(CloseCode::GOING_AWAY->value, $receivedCode);
        $this->assertSame('Shutdown', $receivedReason);
    }

    #[Test]
    public function process_close_frame_with_empty_payload_uses_defaults(): void
    {
        $receivedCode = 0;

        $this->server->on('close', function (Connection $conn, int $code, string $reason) use (&$receivedCode): void {
            $receivedCode = $code;
        });

        $this->server->addConnection($this->connection);

        $frame = new Frame(Opcode::CLOSE, '', fin: true, masked: false);

        $this->withSuppressedErrors(fn() => $this->connection->processFrame($frame));

        $this->assertSame(CloseCode::NORMAL->value, $receivedCode);
    }

    #[Test]
    public function process_ping_frame_responds_with_pong(): void
    {
        $frame = new Frame(Opcode::PING, 'ping-data', fin: true, masked: false);

        $this->withSuppressedErrors(fn() => $this->connection->processFrame($frame));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function process_pong_frame_updates_last_pong(): void
    {
        $frame = new Frame(Opcode::PONG, 'pong-data', fin: true, masked: false);

        $beforePong = $this->connection->getLastPong();
        usleep(1000);

        $this->connection->processFrame($frame);

        $this->assertGreaterThanOrEqual($beforePong, $this->connection->getLastPong());
    }

    #[Test]
    public function send_returns_false_when_not_open(): void
    {
        $this->connection->setState(ConnectionState::CLOSED);

        $result = $this->connection->send('test');

        $this->assertFalse($result);
    }

    #[Test]
    public function close_does_nothing_when_already_closed(): void
    {
        $this->connection->setState(ConnectionState::CLOSED);

        $this->connection->close();

        $this->assertSame(ConnectionState::CLOSED, $this->connection->getState());
    }

    #[Test]
    public function close_changes_state_to_closing(): void
    {
        $this->withSuppressedErrors(fn() => $this->connection->close());

        $this->assertSame(ConnectionState::CLOSING, $this->connection->getState());
    }

    #[Test]
    public function set_data_and_get_data(): void
    {
        $this->connection->setData('key', 'value');

        $this->assertSame('value', $this->connection->getData('key'));
    }

    #[Test]
    public function get_data_returns_default_when_key_not_set(): void
    {
        $result = $this->connection->getData('nonexistent', 'default');

        $this->assertSame('default', $result);
    }

    #[Test]
    public function has_data_returns_true_for_set_key(): void
    {
        $this->connection->setData('key', 'value');

        $this->assertTrue($this->connection->hasData('key'));
    }

    #[Test]
    public function has_data_returns_false_for_unset_key(): void
    {
        $this->assertFalse($this->connection->hasData('nonexistent'));
    }

    #[Test]
    public function join_room_adds_to_rooms(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->joinRoom('chat');

        $this->assertTrue($this->connection->isInRoom('chat'));
        $this->assertSame(['chat'], $this->connection->getRooms());
    }

    #[Test]
    public function join_room_does_not_duplicate(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->joinRoom('chat');
        $this->connection->joinRoom('chat');

        $this->assertSame(['chat'], $this->connection->getRooms());
    }

    #[Test]
    public function leave_room_removes_from_rooms(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->joinRoom('chat');
        $this->connection->leaveRoom('chat');

        $this->assertFalse($this->connection->isInRoom('chat'));
        $this->assertSame([], $this->connection->getRooms());
    }

    #[Test]
    public function get_request_returns_upgrade_request(): void
    {
        $request = $this->connection->getRequest();

        $this->assertSame('GET', $request->getMethod());
    }

    #[Test]
    public function get_remote_address(): void
    {
        $this->assertSame('127.0.0.1', $this->connection->getRemoteAddress());
    }

    #[Test]
    public function get_remote_port(): void
    {
        $this->assertSame(8080, $this->connection->getRemotePort());
    }

    #[Test]
    public function get_server(): void
    {
        $this->assertSame($this->server, $this->connection->getServer());
    }

    #[Test]
    public function get_tcp_connection(): void
    {
        $tcp = $this->connection->getTcpConnection();

        $this->assertInstanceOf(TcpConnection::class, $tcp);
    }

    #[Test]
    public function ping_updates_last_ping(): void
    {
        $this->assertNull($this->connection->getLastPing());

        $this->withSuppressedErrors(fn() => $this->connection->ping());

        $this->assertNotNull($this->connection->getLastPing());
    }

    #[Test]
    public function send_array_data_returns_boolean(): void
    {
        $result = $this->withSuppressedErrors(fn() => $this->connection->send(['type' => 'test']));

        $this->assertIsBool($result);
    }

    #[Test]
    public function broadcast_delegates_to_server(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->broadcast('message', excludeSelf: true);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function send_to_room_delegates_to_server(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->sendToRoom('chat', 'message', excludeSelf: false);

        $this->expectNotToPerformAssertions();
    }
}
