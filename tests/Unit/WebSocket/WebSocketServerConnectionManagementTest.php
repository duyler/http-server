<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Connection\Connection as TcpConnection;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\CloseCode;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Socket;
use Throwable;

class WebSocketServerConnectionManagementTest extends TestCase
{
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
            @socket_close($socket);
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
        $idProp->setAccessible(true);
        $idProp->setValue($conn, $id);

        $stateProp = $reflection->getProperty('state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($conn, ConnectionState::OPEN);

        $tcpProp = $reflection->getProperty('tcpConnection');
        $tcpProp->setAccessible(true);
        $tcpProp->setValue($conn, $tcpConnection);

        $serverProp = $reflection->getProperty('server');
        $serverProp->setAccessible(true);
        $serverProp->setValue($conn, $this->server);

        $requestProp = $reflection->getProperty('upgradeRequest');
        $requestProp->setAccessible(true);
        $requestProp->setValue($conn, $request);

        $pongProp = $reflection->getProperty('lastPong');
        $pongProp->setAccessible(true);
        $pongProp->setValue($conn, microtime(true));

        return $conn;
    }

    public function testAddConnectionEmitsConnectEvent(): void
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

    public function testGetConnectionReturnsAddedConnection(): void
    {
        $conn = $this->createWsConnection('conn_42');
        $this->server->addConnection($conn);

        $found = $this->server->getConnection('conn_42');

        $this->assertSame($conn, $found);
    }

    public function testGetConnectionsReturnsAll(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $connections = $this->server->getConnections();

        $this->assertCount(2, $connections);
    }

    public function testRemoveConnectionRemovesFromRooms(): void
    {
        $conn = $this->createWsConnection('conn_1');

        $roomsProp = new ReflectionProperty($conn, 'rooms');
        $roomsProp->setAccessible(true);
        $roomsProp->setValue($conn, ['room1', 'room2']);

        $this->server->addConnection($conn);
        $this->server->addConnectionToRoom($conn, 'room1');
        $this->server->addConnectionToRoom($conn, 'room2');

        $this->server->removeConnection($conn);

        $this->assertNull($this->server->getConnection('conn_1'));
        $this->assertSame(0, $this->server->getRoomCount('room1'));
        $this->assertSame(0, $this->server->getRoomCount('room2'));
    }

    public function testRemoveConnectionWithoutRooms(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->removeConnection($conn);

        $this->assertNull($this->server->getConnection('conn_1'));
    }

    public function testBroadcastSkipsClosedConnections(): void
    {
        $openConn = $this->createWsConnection('conn_open');
        $closedConn = $this->createWsConnection('conn_closed');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $this->server->addConnection($openConn);
        $this->server->addConnection($closedConn);

        $this->server->broadcast('hello');

        $this->expectNotToPerformAssertions();
    }

    public function testBroadcastExcludesConnection(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->broadcast('hello', $conn1);

        $this->expectNotToPerformAssertions();
    }

    public function testAddConnectionToRoom(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'chat');

        $this->assertSame(1, $this->server->getRoomCount('chat'));
        $roomConns = $this->server->getRoomConnections('chat');
        $this->assertArrayHasKey('conn_1', $roomConns);
    }

    public function testAddMultipleConnectionsToRoom(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $this->assertSame(2, $this->server->getRoomCount('chat'));
    }

    public function testRemoveConnectionFromRoom(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'chat');
        $this->server->removeConnectionFromRoom($conn, 'chat');

        $this->assertSame(0, $this->server->getRoomCount('chat'));
    }

    public function testRemoveConnectionFromRoomDeletesEmptyRoom(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'chat');
        $this->server->removeConnectionFromRoom($conn, 'chat');

        $this->assertSame(0, $this->server->getRoomCount('chat'));
        $this->assertSame([], $this->server->getRoomConnections('chat'));
    }

    public function testRemoveNonexistentConnectionFromRoomDoesNotThrow(): void
    {
        $conn = $this->createWsConnection('conn_1');

        $this->server->removeConnectionFromRoom($conn, 'nonexistent');

        $this->expectNotToPerformAssertions();
    }

    public function testBroadcastToRoomSkipsClosedConnections(): void
    {
        $openConn = $this->createWsConnection('conn_open');
        $closedConn = $this->createWsConnection('conn_closed');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $this->server->addConnection($openConn);
        $this->server->addConnection($closedConn);

        $this->server->addConnectionToRoom($openConn, 'chat');
        $this->server->addConnectionToRoom($closedConn, 'chat');

        $this->server->broadcastToRoom('chat', 'msg');

        $this->expectNotToPerformAssertions();
    }

    public function testBroadcastToRoomExcludesConnection(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $this->server->broadcastToRoom('chat', 'msg', $conn1);

        $this->expectNotToPerformAssertions();
    }

    public function testCloseAllClosesAllConnections(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->closeAll();

        $this->assertSame(ConnectionState::CLOSING, $conn1->getState());
        $this->assertSame(ConnectionState::CLOSING, $conn2->getState());
    }

    public function testCloseAllWithCustomCodeAndReason(): void
    {
        $conn = $this->createWsConnection('conn_1');
        $this->server->addConnection($conn);

        $this->server->closeAll(CloseCode::NORMAL->value, 'Custom reason');

        $this->assertSame(ConnectionState::CLOSING, $conn->getState());
    }

    public function testCleanupClosedConnectionsRemovesClosed(): void
    {
        $openConn = $this->createWsConnection('conn_open');
        $closedConn = $this->createWsConnection('conn_closed');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $roomsProp = new ReflectionProperty($closedConn, 'rooms');
        $roomsProp->setAccessible(true);
        $roomsProp->setValue($closedConn, []);

        $this->server->addConnection($openConn);
        $this->server->addConnection($closedConn);

        $this->assertSame(2, $this->server->getConnectionCount());

        $removed = $this->server->cleanupClosedConnections();

        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->server->getConnectionCount());
    }

    public function testProcessPingsSkipsNonOpenConnections(): void
    {
        $config = new WebSocketConfig(pingInterval: 1, pongTimeout: 1);
        $server = new WebSocketServer($config);

        $closedConn = $this->createWsConnection('conn_1');

        $stateProp = new ReflectionProperty($closedConn, 'state');
        $stateProp->setAccessible(true);
        $stateProp->setValue($closedConn, ConnectionState::CLOSED);

        $server->addConnection($closedConn);

        $server->processPings();

        $this->expectNotToPerformAssertions();
    }

    public function testProcessPingsSendsPingWhenNoLastPing(): void
    {
        $config = new WebSocketConfig(pingInterval: 1, pongTimeout: 10);
        $server = new WebSocketServer($config);

        $conn = $this->createWsConnection('conn_1');

        $lastPingProp = new ReflectionProperty($conn, 'lastPing');
        $lastPingProp->setAccessible(true);
        $lastPingProp->setValue($conn, null);

        $server->addConnection($conn);

        $server->processPings();

        $this->assertNotNull($conn->getLastPing());
    }

    public function testHandleConnectionErrorEmitsErrorEvent(): void
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

    public function testGetRoomCountReturnsCorrectCount(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $this->assertSame(2, $this->server->getRoomCount('chat'));
    }

    public function testGetRoomConnectionsReturnsConnectionsForExistingRoom(): void
    {
        $conn1 = $this->createWsConnection('conn_1');
        $conn2 = $this->createWsConnection('conn_2');

        $this->server->addConnectionToRoom($conn1, 'chat');
        $this->server->addConnectionToRoom($conn2, 'chat');

        $roomConns = $this->server->getRoomConnections('chat');

        $this->assertCount(2, $roomConns);
    }
}
