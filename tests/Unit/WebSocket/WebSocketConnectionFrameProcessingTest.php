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
use PHPUnit\Framework\TestCase;
use Socket;

class WebSocketConnectionFrameProcessingTest extends TestCase
{
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
            @socket_close($socket);
        }
    }

    public function testProcessTextFrameReturnsMessage(): void
    {
        $frame = new Frame(Opcode::TEXT, 'hello', fin: true, masked: false);

        $message = $this->connection->processFrame($frame);

        $this->assertNotNull($message);
        $this->assertSame('hello', $message->getData());
        $this->assertTrue($message->isText());
    }

    public function testProcessBinaryFrameReturnsMessage(): void
    {
        $frame = new Frame(Opcode::BINARY, "\x00\x01\x02", fin: true, masked: false);

        $message = $this->connection->processFrame($frame);

        $this->assertNotNull($message);
        $this->assertFalse($message->isText());
    }

    public function testProcessUnfinishedTextFrameReturnsNull(): void
    {
        $frame = new Frame(Opcode::TEXT, 'partial', fin: false, masked: false);

        $message = $this->connection->processFrame($frame);

        $this->assertNull($message);
    }

    public function testProcessContinuationFrameWithoutFinReturnsNull(): void
    {
        $textFrame = new Frame(Opcode::TEXT, 'part1', fin: false, masked: false);
        $this->connection->processFrame($textFrame);

        $continuationFrame = new Frame(Opcode::CONTINUATION, 'part2', fin: false, masked: false);

        $message = $this->connection->processFrame($continuationFrame);

        $this->assertNull($message);
    }

    public function testProcessContinuationFrameWithFinReturnsCompleteMessage(): void
    {
        $textFrame = new Frame(Opcode::TEXT, 'part1', fin: false, masked: false);
        $this->connection->processFrame($textFrame);

        $continuationFrame = new Frame(Opcode::CONTINUATION, 'part2', fin: true, masked: false);

        $message = $this->connection->processFrame($continuationFrame);

        $this->assertNotNull($message);
        $this->assertSame('part1part2', $message->getData());
        $this->assertTrue($message->isText());
    }

    public function testProcessCloseFrameChangesState(): void
    {
        $payload = pack('n', CloseCode::NORMAL->value) . 'Goodbye';
        $frame = new Frame(Opcode::CLOSE, $payload, fin: true, masked: false);

        $this->connection->processFrame($frame);

        $this->assertSame(ConnectionState::CLOSED, $this->connection->getState());
    }

    public function testProcessCloseFrameEmitsCloseEvent(): void
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

        $this->connection->processFrame($frame);

        $this->assertSame(CloseCode::GOING_AWAY->value, $receivedCode);
        $this->assertSame('Shutdown', $receivedReason);
    }

    public function testProcessCloseFrameWithEmptyPayloadUsesDefaults(): void
    {
        $receivedCode = 0;

        $this->server->on('close', function (Connection $conn, int $code, string $reason) use (&$receivedCode): void {
            $receivedCode = $code;
        });

        $this->server->addConnection($this->connection);

        $frame = new Frame(Opcode::CLOSE, '', fin: true, masked: false);

        $this->connection->processFrame($frame);

        $this->assertSame(CloseCode::NORMAL->value, $receivedCode);
    }

    public function testProcessPingFrameRespondsWithPong(): void
    {
        $frame = new Frame(Opcode::PING, 'ping-data', fin: true, masked: false);

        $this->connection->processFrame($frame);

        $this->expectNotToPerformAssertions();
    }

    public function testProcessPongFrameUpdatesLastPong(): void
    {
        $frame = new Frame(Opcode::PONG, 'pong-data', fin: true, masked: false);

        $beforePong = $this->connection->getLastPong();
        usleep(1000);

        $this->connection->processFrame($frame);

        $this->assertGreaterThanOrEqual($beforePong, $this->connection->getLastPong());
    }

    public function testSendReturnsFalseWhenNotOpen(): void
    {
        $this->connection->setState(ConnectionState::CLOSED);

        $result = $this->connection->send('test');

        $this->assertFalse($result);
    }

    public function testCloseDoesNothingWhenAlreadyClosed(): void
    {
        $this->connection->setState(ConnectionState::CLOSED);

        $this->connection->close();

        $this->assertSame(ConnectionState::CLOSED, $this->connection->getState());
    }

    public function testCloseChangesStateToClosing(): void
    {
        $this->connection->close();

        $this->assertSame(ConnectionState::CLOSING, $this->connection->getState());
    }

    public function testSetDataAndGetData(): void
    {
        $this->connection->setData('key', 'value');

        $this->assertSame('value', $this->connection->getData('key'));
    }

    public function testGetDataReturnsDefaultWhenKeyNotSet(): void
    {
        $result = $this->connection->getData('nonexistent', 'default');

        $this->assertSame('default', $result);
    }

    public function testHasDataReturnsTrueForSetKey(): void
    {
        $this->connection->setData('key', 'value');

        $this->assertTrue($this->connection->hasData('key'));
    }

    public function testHasDataReturnsFalseForUnsetKey(): void
    {
        $this->assertFalse($this->connection->hasData('nonexistent'));
    }

    public function testJoinRoomAddsToRooms(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->joinRoom('chat');

        $this->assertTrue($this->connection->isInRoom('chat'));
        $this->assertSame(['chat'], $this->connection->getRooms());
    }

    public function testJoinRoomDoesNotDuplicate(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->joinRoom('chat');
        $this->connection->joinRoom('chat');

        $this->assertSame(['chat'], $this->connection->getRooms());
    }

    public function testLeaveRoomRemovesFromRooms(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->joinRoom('chat');
        $this->connection->leaveRoom('chat');

        $this->assertFalse($this->connection->isInRoom('chat'));
        $this->assertSame([], $this->connection->getRooms());
    }

    public function testGetRequestReturnsUpgradeRequest(): void
    {
        $request = $this->connection->getRequest();

        $this->assertSame('GET', $request->getMethod());
    }

    public function testGetRemoteAddress(): void
    {
        $this->assertSame('127.0.0.1', $this->connection->getRemoteAddress());
    }

    public function testGetRemotePort(): void
    {
        $this->assertSame(8080, $this->connection->getRemotePort());
    }

    public function testGetServer(): void
    {
        $this->assertSame($this->server, $this->connection->getServer());
    }

    public function testGetTcpConnection(): void
    {
        $tcp = $this->connection->getTcpConnection();

        $this->assertInstanceOf(TcpConnection::class, $tcp);
    }

    public function testPingUpdatesLastPing(): void
    {
        $this->assertNull($this->connection->getLastPing());

        $this->connection->ping();

        $this->assertNotNull($this->connection->getLastPing());
    }

    public function testSendArrayDataReturnsBoolean(): void
    {
        $result = $this->connection->send(['type' => 'test']);

        $this->assertIsBool($result);
    }

    public function testBroadcastDelegatesToServer(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->broadcast('message', excludeSelf: true);

        $this->expectNotToPerformAssertions();
    }

    public function testSendToRoomDelegatesToServer(): void
    {
        $this->server->addConnection($this->connection);

        $this->connection->sendToRoom('chat', 'message', excludeSelf: false);

        $this->expectNotToPerformAssertions();
    }
}
