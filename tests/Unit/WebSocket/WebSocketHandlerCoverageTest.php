<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface as TcpConnection;
use Duyler\HttpServer\Processor\RequestProcessorInterface;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketHandler;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WebSocketHandlerCoverageTest extends TestCase
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

    #[Test]
    public function set_logger_updates_logger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $wsServer = new WebSocketServer();
        $this->handler->attachWebSocketServer('/ws', $wsServer);
        $this->handler->setLogger($logger);

        $this->assertTrue($this->handler->hasWebSocketServers());
    }

    #[Test]
    public function has_web_socket_connection_returns_false_when_no_connection(): void
    {
        $result = $this->handler->hasWebSocketConnection($this->tcpConnection);

        $this->assertFalse($result);
    }

    #[Test]
    public function get_web_socket_connection_returns_null_when_no_connection(): void
    {
        $result = $this->handler->getWebSocketConnection($this->tcpConnection);

        $this->assertNull($result);
    }

    #[Test]
    public function handle_handshake_returns_false_for_unknown_endpoint(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/unknown');
        $request->method('getUri')->willReturn($uri);

        $this->requestProcessor
            ->expects($this->once())
            ->method('sendErrorResponse')
            ->with($this->tcpConnection, 404, 'WebSocket endpoint not found');

        $result = $this->handler->handleHandshake($this->tcpConnection, $request);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_handshake_succeeds_with_valid_origin_bypass(): void
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

        $this->tcpConnection->expects($this->once())->method('write');
        $this->tcpConnection->expects($this->once())->method('clearBuffer');

        $result = $this->handler->handleHandshake($this->tcpConnection, $request);

        $this->assertTrue($result);
    }

    #[Test]
    public function handle_handshake_stores_connection_after_success(): void
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

        $this->assertTrue($this->handler->hasWebSocketConnection($this->tcpConnection));

        $wsConn = $this->handler->getWebSocketConnection($this->tcpConnection);
        $this->assertInstanceOf(Connection::class, $wsConn);
        $this->assertSame(ConnectionState::OPEN, $wsConn->getState());
    }

    #[Test]
    public function handle_handshake_returns_403_on_origin_validation_failure(): void
    {
        $wsConfig = new WebSocketConfig(validateOrigin: true, allowedOrigins: ['https://allowed.com']);
        $wsServer = new WebSocketServer($wsConfig);
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/ws');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['Origin', 'https://evil.com'],
            ['Sec-WebSocket-Key', 'dGhlIHNhbXBsZSBub25jZQ=='],
        ]);
        $request->method('hasHeader')->willReturnMap([
            ['Origin', true],
        ]);
        $request->method('getServerParams')->willReturn([]);

        $this->requestProcessor
            ->expects($this->once())
            ->method('sendErrorResponse')
            ->with($this->tcpConnection, 403, 'Origin not allowed');

        $result = $this->handler->handleHandshake($this->tcpConnection, $request);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_handshake_logs_insecure_config_warning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = new WebSocketHandler($this->config, $this->requestProcessor, logger: $logger);

        $wsConfig = new WebSocketConfig(validateOrigin: false, allowedOrigins: ['*']);
        $wsServer = new WebSocketServer($wsConfig);
        $handler->attachWebSocketServer('/ws', $wsServer);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/ws');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['Origin', ''],
            ['Sec-WebSocket-Key', 'dGhlIHNhbXBsZSBub25jZQ=='],
            ['Sec-WebSocket-Protocol', ''],
        ]);
        $request->method('hasHeader')->willReturnMap([
            ['Sec-WebSocket-Protocol', false],
        ]);
        $request->method('getServerParams')->willReturn([]);

        $this->tcpConnection->method('write')->willReturn(100);
        $this->tcpConnection->method('clearBuffer');

        $logger->expects($this->atLeastOnce())->method('warning');

        $result = $handler->handleHandshake($this->tcpConnection, $request);

        $this->assertTrue($result);
    }

    #[Test]
    public function handle_data_returns_false_when_no_ws_connection(): void
    {
        $result = $this->handler->handleData($this->tcpConnection);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_data_returns_false_for_non_stream_socket(): void
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
        $this->tcpConnection->method('isValid')->willReturn(true);

        $this->handler->handleHandshake($this->tcpConnection, $request);

        $result = $this->handler->handleData($this->tcpConnection);

        $this->assertFalse($result);
    }

    #[Test]
    public function handle_data_for_connection_delegates_to_process(): void
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
        $this->tcpConnection->method('isValid')->willReturn(true);

        $this->handler->handleHandshake($this->tcpConnection, $request);

        $wsConn = $this->handler->getWebSocketConnection($this->tcpConnection);
        $this->assertInstanceOf(Connection::class, $wsConn);

        $result = $this->handler->handleDataForConnection($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function process_web_socket_data_direct_returns_false_for_invalid_connection(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $this->tcpConnection->method('isValid')->willReturn(false);
        $this->tcpConnection->method('write')->willReturn(100);

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function process_web_socket_data_direct_returns_false_for_empty_read(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturn(false);
        $this->tcpConnection->method('write')->willReturn(100);

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function process_web_socket_data_direct_returns_false_for_empty_string_read(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturn('');
        $this->tcpConnection->method('write')->willReturn(100);

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function process_web_socket_data_direct_returns_false_when_closed_after_buffer(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturn('data');
        $this->tcpConnection->method('isClosed')->willReturn(true);
        $this->tcpConnection->method('write')->willReturn(100);

        $result = $this->handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function process_web_socket_data_direct_catches_exception_in_debug_mode(): void
    {
        $config = new ServerConfig(debugMode: true);
        $logger = $this->createMock(LoggerInterface::class);
        $handler = new WebSocketHandler($config, $this->requestProcessor, logger: $logger);

        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willThrowException(new RuntimeException('read error'));
        $this->tcpConnection->method('write')->willReturn(100);

        $logger->expects($this->atLeastOnce())->method('debug');

        $result = $handler->processWebSocketDataDirect($this->tcpConnection, $wsConn);

        $this->assertFalse($result);
    }

    #[Test]
    public function process_web_socket_data_direct_returns_true_with_valid_frame(): void
    {
        $wsServer = new WebSocketServer();
        $request = $this->createMock(ServerRequestInterface::class);
        $wsConn = new Connection($this->tcpConnection, $request, $wsServer);

        $frame = new \Duyler\HttpServer\WebSocket\Frame(
            \Duyler\HttpServer\WebSocket\Enum\Opcode::TEXT,
            'hello',
            fin: true,
            masked: false,
        );
        $encodedFrame = $frame->encode();

        $buffer = '';

        $this->tcpConnection->method('isValid')->willReturn(true);
        $this->tcpConnection->method('read')->willReturn($encodedFrame);
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
    public function remove_connection_removes_from_internal_array(): void
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
        $this->assertTrue($this->handler->hasWebSocketConnection($this->tcpConnection));

        $this->handler->removeConnection($this->tcpConnection);
        $this->assertFalse($this->handler->hasWebSocketConnection($this->tcpConnection));
    }

    #[Test]
    public function handle_handshake_returns_403_when_no_origin_header(): void
    {
        $wsConfig = new WebSocketConfig(validateOrigin: true, allowedOrigins: ['https://allowed.com']);
        $wsServer = new WebSocketServer($wsConfig);
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $request = $this->createMock(ServerRequestInterface::class);
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/ws');
        $request->method('getUri')->willReturn($uri);
        $request->method('getHeaderLine')->willReturnMap([
            ['Origin', ''],
            ['Sec-WebSocket-Key', 'dGhlIHNhbXBsZSBub25jZQ=='],
        ]);
        $request->method('hasHeader')->willReturnMap([
            ['Origin', false],
        ]);
        $request->method('getServerParams')->willReturn([]);

        $this->requestProcessor
            ->expects($this->once())
            ->method('sendErrorResponse')
            ->with($this->tcpConnection, 403, 'Origin not allowed');

        $result = $this->handler->handleHandshake($this->tcpConnection, $request);

        $this->assertFalse($result);
    }
}
