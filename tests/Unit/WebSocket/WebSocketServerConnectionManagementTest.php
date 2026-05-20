<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Connection\Connection as TcpConnection;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\Tests\Support\ErrorReportingScope;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\CloseCode;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Socket;
use Throwable;

class WebSocketServerConnectionManagementTest extends TestCase
{
    use ErrorReportingScope;
    private WebSocketServer $server;

    /** @var array<Socket> */
    private array $sockets = [];

    #[Override]
    protected function setUp(): void
    {
        $this->server = new WebSocketServer(new WebSocketConfig());
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            $this->withSuppressedErrors(static fn() => socket_close($socket));
        }
        $this->sockets = [];
    }

    private function createWsConnection(string $id = 'conn_1'): Connection
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        assert($socket !== false);
        $this->sockets[] = $socket;

        $resource = new StreamSocketResource($socket);
        $tcpConnection = new TcpConnection($resource, '127.0.0.1', 8080);

        $request = new ServerRequest('GET', '/ws');

        $reflection = new ReflectionClass(Connection::class);
        $conn = $reflection->newInstanceWithoutConstructor();

        $idProp = $reflection->getProperty('id');
        $idProp->setValue($conn, $id);

        $stateProp = $reflection->getProperty('state');
        $stateProp->setValue($conn, ConnectionState::OPEN);

        $tcpProp = $reflection->getProperty('tcpConnection');
        $tcpProp->setValue($conn, $tcpConnection);

        $serverProp = $reflection->getProperty('server');
        $serverProp->setValue($conn, $this->server);

        $requestProp = $reflection->getProperty('upgradeRequest');
        $requestProp->setValue($conn, $request);

        $pongProp = $reflection->getProperty('lastPong');
        $pongProp->setValue($conn, microtime(true));

        return $conn;
    }

    #[Test]
    public function add_connection_emits_connect_event(): void
    {
        $receivedConn = null;
        $this->server->on('connect', function (Connection $conn) use (&$receivedConn): void {
            $receivedConn = $conn;
        });

        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->assertSame($conn, $receivedConn);
        $this->assertSame(1, $this->server->getConnectionCount());
    }

    #[Test]
    public function get_connection_returns_added_connection(): void
    {
        $conn = $this->createWsConnection('conn_42');
        $this->server->addConnection($conn);

        $found = $this->server->getConnection('conn_42');

        $this->assertSame($conn, $found);
    }

    #[Test]
    public function get_connections_returns_all(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $connections = $this->server->getConnections();

        $this->assertCount(2, $connections);
    }

    #[Test]
    public function remove_connection_removes_from_rooms(): void
    {
        $conn = $this->createWsConnection('conn_1');

        $roomsProp = new ReflectionProperty($conn, 'rooms');
        $roomsProp->setValue($conn, ['room1', 'room2']);

        $this->server->addConnection($conn);
        $this->server->addConnectionToRoom($conn, 'room1');
        $this->server->addConnectionToRoom($conn, 'room2');

        $this->server->removeConnection($conn);

        $this->assertNull($this->server->getConnection('conn_1'));
        $this->assertSame(0, $this->server->getRoomCount('room1'));
        $this->assertSame(0, $this->server->getRoomCount('room2'));
    }

    #[Test]
    public function remove_connection_without_rooms(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->removeConnection($conn);

        $this->assertNull($this->server->getConnection('conn_1'));
    }

    #[Test]
    public function broadcast_skips_closed_connections(): void
    {
        $openConn = $this->createWsConnection('conn_open');
        $closedConn = $this->createWsConnection('conn_closed');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $this->server->addConnection($openConn);
        $this->server->addConnection($closedConn);

        $this->withSuppressedErrors(fn() => $this->server->broadcast('hello'));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function broadcast_excludes_connection(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->withSuppressedErrors(fn() => $this->server->broadcast('hello', $conn1));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function add_connection_to_room(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'chat');

        $this->assertSame(1, $this->server->getRoomCount('chat'));
        $roomConns = $this->server->getRoomConnections('chat');
        $this->assertArrayHasKey('conn_1', $roomConns);
    }

    #[Test]
    public function add_multiple_connections_to_room(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $this->assertSame(2, $this->server->getRoomCount('chat'));
    }

    #[Test]
    public function remove_connection_from_room(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'chat');
        $this->server->removeConnectionFromRoom($conn, 'chat');

        $this->assertSame(0, $this->server->getRoomCount('chat'));
    }

    #[Test]
    public function remove_connection_from_room_deletes_empty_room(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'chat');
        $this->server->removeConnectionFromRoom($conn, 'chat');

        $this->assertSame(0, $this->server->getRoomCount('chat'));
        $this->assertSame([], $this->server->getRoomConnections('chat'));
    }

    #[Test]
    public function remove_nonexistent_connection_from_room_does_not_throw(): void
    {
        $conn = $this->createWsConnection('conn_1');

        $this->server->removeConnectionFromRoom($conn, 'nonexistent');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function broadcast_to_room_skips_closed_connections(): void
    {
        $openConn = $this->createWsConnection('conn_open');
        $closedConn = $this->createWsConnection('conn_closed');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $this->server->addConnection($openConn);
        $this->server->addConnection($closedConn);

        $this->server->addConnectionToRoom($openConn, 'chat');
        $this->server->addConnectionToRoom($closedConn, 'chat');

        $this->withSuppressedErrors(fn() => $this->server->broadcastToRoom('chat', 'msg'));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function broadcast_to_room_excludes_connection(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $this->withSuppressedErrors(fn() => $this->server->broadcastToRoom('chat', 'msg', $conn1));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function close_all_closes_all_connections(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->withSuppressedErrors(fn() => $this->server->closeAll());

        $this->assertSame(ConnectionState::CLOSING, $conn1->getState());
        $this->assertSame(ConnectionState::CLOSING, $conn2->getState());
    }

    #[Test]
    public function close_all_with_custom_code_and_reason(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->withSuppressedErrors(fn() => $this->server->closeAll(CloseCode::NORMAL->value, 'Custom reason'));

        $this->assertSame(ConnectionState::CLOSING, $conn->getState());
    }

    #[Test]
    public function cleanup_closed_connections_removes_closed(): void
    {
        $openConn = $this->createWsConnection('conn_open');
        $closedConn = $this->createWsConnection('conn_closed');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $roomsProp = new ReflectionProperty($closedConn, 'rooms');
        $roomsProp->setValue($closedConn, []);

        $this->server->addConnection($openConn);
        $this->server->addConnection($closedConn);

        $this->assertSame(2, $this->server->getConnectionCount());

        $removed = $this->server->cleanupClosedConnections();

        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->server->getConnectionCount());
    }

    #[Test]
    public function process_pings_skips_non_open_connections(): void
    {
        $config = new WebSocketConfig(pingInterval: 1, pongTimeout: 1);
        $server = new WebSocketServer($config);

        $closedConn = $this->createWsConnection('conn_1');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $server->addConnection($closedConn);

        $this->withSuppressedErrors(fn() => $server->processPings());

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function process_pings_sends_ping_when_no_last_ping(): void
    {
        $config = new WebSocketConfig(pingInterval: 1, pongTimeout: 10);
        $server = new WebSocketServer($config);

        $conn = $this->createWsConnection('conn_1');

        $lastPingProp = new ReflectionProperty($conn, 'lastPing');
        $lastPingProp->setValue($conn, null);

        $server->addConnection($conn);

        $this->withSuppressedErrors(fn() => $server->processPings());

        $this->assertNotNull($conn->getLastPing());
    }

    #[Test]
    public function handle_connection_error_emits_error_event(): void
    {
        $error = new RuntimeException('test error');
        $receivedConn = null;
        $receivedError = null;

        $this->server->on('error', function (Connection $conn, Throwable $err) use (&$receivedConn, &$receivedError): void {
            $receivedConn = $conn;
            $receivedError = $err;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $this->server->setLogger($logger);

        $conn = $this->createWsConnection('conn_1');
        $this->server->handleConnectionError($conn, $error);

        $this->assertSame($conn, $receivedConn);
        $this->assertSame($error, $receivedError);
    }

    #[Test]
    public function get_room_count_returns_correct_count(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $this->assertSame(2, $this->server->getRoomCount('chat'));
    }

    #[Test]
    public function get_room_connections_returns_connections_for_existing_room(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $roomConns = $this->server->getRoomConnections('chat');

        $this->assertCount(2, $roomConns);
    }
}
