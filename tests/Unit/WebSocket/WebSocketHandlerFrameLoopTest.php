<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface as TcpConnection;
use Duyler\HttpServer\Processor\RequestProcessorInterface;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Frame;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketHandler;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

class WebSocketHandlerFrameLoopTest extends TestCase
{
    private ServerConfig $config;

    /** @var RequestProcessorInterface&MockObject */
    private RequestProcessorInterface $requestProcessor;

    /** @var TcpConnection&MockObject */
    private TcpConnection $tcpConnection;

    /** @var SocketResourceInterface&MockObject */
    private SocketResourceInterface $socket;

    private WebSocketHandler $handler;

    protected function setUp(): void
    {
        $this->config = new ServerConfig();
        $this->requestProcessor = $this->createMock(RequestProcessorInterface::class);
        $this->socket = $this->createMock(SocketResourceInterface::class);
        $this->tcpConnection = $this->createMock(TcpConnection::class);
        $this->tcpConnection->method('getSocket')->willReturn($this->socket);
        $this->tcpConnection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->handler = new WebSocketHandler($this->config, $this->requestProcessor);
    }

    private function establishConnection(): Connection
    {
        $wsConfig = new WebSocketConfig(validateOrigin: false, allowedOrigins: ['https://example.com']);
        $wsServer = new WebSocketServer($wsConfig);
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/ws');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['Origin', 'https://example.com'],
            ['Sec-WebSocket-Key', 'dGhlIHNhbXBsZSBub25jZQ=='],
            ['Sec-WebSocket-Protocol', ''],
        ]);
        $request->method('hasHeader')->willReturnMap([
            ['Sec-WebSocket-Protocol', false],
        ]);
        $request->method('getServerParams')->willReturn([]);

        $this->tcpConnection->method('write')->willReturn(100);
        $this->tcpConnection->method('clearBuffer');

        $this->handler->handleHandshake($this->tcpConnection, $request);

        $wsConn = $this->handler->getWebSocketConnection($this->tcpConnection);
        assert($wsConn instanceof Connection);

        return $wsConn;
    }

    #[Test]
    public function process_frame_loop_with_remaining_buffer_data(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $frame1 = new Frame(Opcode::TEXT, 'hello', fin: true, masked: false);
        $frame2 = new Frame(Opcode::TEXT, 'world', fin: true, masked: false);
        $encodedBoth = $frame1->encode() . $frame2->encode();

        $buffer = '';
        $readCallCount = 0;

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturnCallback(function () use ($encodedBoth, &$readCallCount): string {
            $readCallCount++;
            if (1 === $readCallCount) {
                return $encodedBoth;
            }
            return '';
        });
        $this->tcpConnection->method('isClosed')->willReturn(false);
        $this->tcpConnection->method('write')->willReturn(100);
        $this->tcpConnection->method('getBuffer')->willReturnCallback(function () use (&$buffer): string {
            return $buffer;
        });
        $this->tcpConnection->method('clearBuffer')->willReturnCallback(function () use (&$buffer): void {
            $buffer = '';
        });
        $this->tcpConnection->method('appendToBuffer')->willReturnCallback(function (string $data) use (&$buffer): void {
            $buffer .= $data;
        });

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertTrue($result);
    }

    #[Test]
    public function process_frame_loop_emits_message_event(): void
    {
        $receivedMessage = null;
        $receivedConn = null;

        $wsServer = new WebSocketServer();
        $wsServer->on('message', function (Connection $conn, \Duyler\HttpServer\WebSocket\Message $msg) use (&$receivedConn, &$receivedMessage): void {
            $receivedConn = $conn;
            $receivedMessage = $msg;
        });

        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $textFrame = new Frame(Opcode::TEXT, 'hello world', fin: true, masked: false);
        $encoded = $textFrame->encode();

        $buffer = '';

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturn($encoded);
        $this->tcpConnection->method('isClosed')->willReturn(false);
        $this->tcpConnection->method('write')->willReturn(100);
        $this->tcpConnection->method('getBuffer')->willReturnCallback(function () use (&$buffer): string {
            return $buffer;
        });
        $this->tcpConnection->method('clearBuffer')->willReturnCallback(function () use (&$buffer): void {
            $buffer = '';
        });
        $this->tcpConnection->method('appendToBuffer')->willReturnCallback(function (string $data) use (&$buffer): void {
            $buffer .= $data;
        });

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertTrue($result);
        $this->assertNotNull($receivedMessage);
        $this->assertSame('hello world', $receivedMessage->getData());
        $this->assertSame($wsConn, $receivedConn);
    }

    #[Test]
    public function process_frame_loop_returns_false_when_closed_after_remaining(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $frame1 = new Frame(Opcode::TEXT, 'first', fin: true, masked: false);
        $frame2 = new Frame(Opcode::TEXT, 'second', fin: true, masked: false);
        $encodedBoth = $frame1->encode() . $frame2->encode();

        $buffer = '';
        $readCallCount = 0;
        $appendCallCount = 0;

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturnCallback(function () use ($encodedBoth, &$readCallCount): string {
            $readCallCount++;
            if (1 === $readCallCount) {
                return $encodedBoth;
            }
            return '';
        });
        $this->tcpConnection->method('write')->willReturn(100);
        $this->tcpConnection->method('getBuffer')->willReturnCallback(function () use (&$buffer): string {
            return $buffer;
        });
        $this->tcpConnection->method('clearBuffer')->willReturnCallback(function () use (&$buffer): void {
            $buffer = '';
        });
        $this->tcpConnection->method('appendToBuffer')->willReturnCallback(function (string $data) use (&$buffer, &$appendCallCount): void {
            $buffer .= $data;
            $appendCallCount++;
        });
        $this->tcpConnection->method('isClosed')->willReturnCallback(function () use (&$appendCallCount): bool {
            return $appendCallCount >= 2;
        });

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_data_returns_false_for_invalid_ws_connection(): void
    {
        $wsConfig = new WebSocketConfig(validateOrigin: false, allowedOrigins: ['https://example.com']);
        $wsServer = new WebSocketServer($wsConfig);
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/ws');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['Origin', 'https://example.com'],
            ['Sec-WebSocket-Key', 'dGhlIHNhbXBsZSBub25jZQ=='],
            ['Sec-WebSocket-Protocol', ''],
        ]);
        $request->method('hasHeader')->willReturnMap([
            ['Sec-WebSocket-Protocol', false],
        ]);
        $request->method('getServerParams')->willReturn([]);

        $this->tcpConnection->method('write')->willReturn(100);
        $this->tcpConnection->method('clearBuffer');

        $this->handler->handleHandshake($this->tcpConnection, $request);

        $this->tcpConnection->method('isValid')->willReturn(false);

        $result = $this->handler->handleData($this->tcpConnection);

        $this->assertFalse($result);
    }
}
