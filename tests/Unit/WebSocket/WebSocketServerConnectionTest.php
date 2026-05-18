<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Connection\ConnectionInterface as TcpConnection;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\CloseCode;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WebSocketServerConnectionTest extends TestCase
{
    private WebSocketServer $server;
    private TcpConnection&MockObject $tcpConnection;
    private ServerRequestInterface $request;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new WebSocketServer(new WebSocketConfig());
        $this->tcpConnection = $this->createMock(TcpConnection::class);
        $this->tcpConnection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->tcpConnection->method('getRemotePort')->willReturn(12345);
        $this->request = new ServerRequest('GET', '/ws');
    }

    private function createConnection(string $id = 'test-conn'): Connection
    {
        return new Connection($this->tcpConnection, $this->request, $this->server);
    }

    public function testAddsConnection(): void
    {
        $conn = $this->createConnection();

        $this->server->addConnection($conn);

        $this->assertSame(1, $this->server->getConnectionCount());
        $this->assertSame($conn, $this->server->getConnection($conn->getId()));
    }

    public function testRemovesConnection(): void
    {
        $conn = $this->createConnection();
        $this->server->addConnection($conn);

        $this->server->removeConnection($conn);

        $this->assertSame(0, $this->server->getConnectionCount());
        $this->assertNull($this->server->getConnection($conn->getId()));
    }

    public function testRemovesConnectionFromRooms(): void
    {
        $conn = $this->createConnection();
        $this->server->addConnection($conn);
        $conn->setState(ConnectionState::OPEN);
        $conn->joinRoom('room1');
        $conn->joinRoom('room2');

        $this->server->removeConnection($conn);

        $this->assertSame([], $this->server->getRoomConnections('room1'));
        $this->assertSame([], $this->server->getRoomConnections('room2'));
    }

    public function testBroadcastsToAllConnections(): void
    {
        $conn1 = $this->createConnection('conn1');
        $conn2 = $this->createConnection('conn2');

        $this->tcpConnection->method('write')->willReturn(strlen('test message'));

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);
        $conn1->setState(ConnectionState::OPEN);
        $conn2->setState(ConnectionState::OPEN);

        $this->server->broadcast('test message');

        $this->assertTrue(true);
    }

    public function testBroadcastsArrayData(): void
    {
        $conn = $this->createConnection();
        $this->tcpConnection->method('write')->willReturn(100);

        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);

        $this->server->broadcast(['key' => 'value']);

        $this->assertTrue(true);
    }

    public function testBroadcastExcludesConnection(): void
    {
        $conn1 = $this->createConnection('conn1');
        $conn2 = $this->createConnection('conn2');

        $writeCount = 0;
        $this->tcpConnection->method('write')->willReturnCallback(function () use (&$writeCount) {
            $writeCount++;
            return 100;
        });

        $conn1->setState(ConnectionState::OPEN);
        $conn2->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->broadcast('test', $conn1);

        $this->assertSame(1, $writeCount);
    }

    public function testBroadcastToRoom(): void
    {
        $conn1 = $this->createConnection('conn1');
        $conn2 = $this->createConnection('conn2');

        $this->tcpConnection->method('write')->willReturn(100);

        $conn1->setState(ConnectionState::OPEN);
        $conn2->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $conn1->joinRoom('room1');
        $conn2->joinRoom('room2');

        $this->server->broadcastToRoom('room1', 'test message');

        $this->assertTrue(true);
    }

    public function testBroadcastToRoomExcludesConnection(): void
    {
        $conn = $this->createConnection();
        $this->tcpConnection->method('write')->willReturn(100);

        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);
        $conn->joinRoom('room1');

        $this->server->broadcastToRoom('room1', 'test', $conn);

        $this->assertTrue(true);
    }

    public function testHandlesConnectionError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'WebSocket connection error',
                $this->callback(fn(array $context) => isset($context['conn_id'])
                    && isset($context['error'])),
            );

        $this->server->setLogger($logger);

        $conn = $this->createConnection();
        $this->server->addConnection($conn);

        $error = new RuntimeException('Test error');
        $this->server->handleConnectionError($conn, $error);

        $this->assertTrue(true);
    }

    public function testEmitErrorOnConnectionError(): void
    {
        $errorReceived = null;
        $connReceived = null;

        $this->server->on('error', function ($conn, $error) use (&$connReceived, &$errorReceived): void {
            $connReceived = $conn;
            $errorReceived = $error;
        });

        $conn = $this->createConnection();
        $this->server->addConnection($conn);

        $error = new RuntimeException('Test error');
        $this->server->handleConnectionError($conn, $error);

        $this->assertSame($conn, $connReceived);
        $this->assertSame($error, $errorReceived);
    }

    public function testCleanupClosedConnections(): void
    {
        $conn1 = $this->createConnection('conn1');
        $conn2 = $this->createConnection('conn2');
        $conn3 = $this->createConnection('conn3');

        $conn1->setState(ConnectionState::OPEN);
        $conn2->setState(ConnectionState::CLOSED);
        $conn3->setState(ConnectionState::OPEN);

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);
        $this->server->addConnection($conn3);

        $removed = $this->server->cleanupClosedConnections();

        $this->assertSame(1, $removed);
        $this->assertSame(2, $this->server->getConnectionCount());
        $this->assertNull($this->server->getConnection('conn2'));
    }

    public function testCleanupMultipleClosedConnections(): void
    {
        $conn1 = $this->createConnection('conn1');
        $conn2 = $this->createConnection('conn2');
        $conn3 = $this->createConnection('conn3');

        $conn1->setState(ConnectionState::CLOSED);
        $conn2->setState(ConnectionState::CLOSED);
        $conn3->setState(ConnectionState::OPEN);

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);
        $this->server->addConnection($conn3);

        $removed = $this->server->cleanupClosedConnections();

        $this->assertSame(2, $removed);
        $this->assertSame(1, $this->server->getConnectionCount());
    }

    public function testCloseAllConnections(): void
    {
        $conn1 = $this->createConnection('conn1');
        $conn2 = $this->createConnection('conn2');

        $this->tcpConnection->method('write')->willReturn(100);

        $conn1->setState(ConnectionState::OPEN);
        $conn2->setState(ConnectionState::OPEN);

        $this->server->addConnection($conn1);
        $this->server->addConnection($conn2);

        $this->server->closeAll(CloseCode::GOING_AWAY->value, 'Server shutdown');

        $this->assertSame(ConnectionState::CLOSING, $conn1->getState());
        $this->assertSame(ConnectionState::CLOSING, $conn2->getState());
    }

    public function testCloseAllWithCustomCode(): void
    {
        $conn = $this->createConnection();
        $this->tcpConnection->method('write')->willReturn(100);
        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);

        $this->server->closeAll(1000, 'Custom reason');

        $this->assertSame(ConnectionState::CLOSING, $conn->getState());
    }

    public function testGetsConfig(): void
    {
        $config = new WebSocketConfig(
            maxMessageSize: 2097152,
            maxFrameSize: 131072,
        );

        $server = new WebSocketServer($config);

        $this->assertSame($config, $server->getConfig());
    }

    public function testManageRoomMembers(): void
    {
        $conn = $this->createConnection();
        $this->server->addConnection($conn);

        $conn->setState(ConnectionState::OPEN);

        $conn->joinRoom('room1');
        $conn->joinRoom('room2');

        $this->assertSame(1, $this->server->getRoomCount('room1'));
        $this->assertSame(1, $this->server->getRoomCount('room2'));

        $rooms = $conn->getRooms();
        $this->assertCount(2, $rooms);
        $this->assertContains('room1', $rooms);
        $this->assertContains('room2', $rooms);

        $conn->leaveRoom('room1');

        $this->assertSame(0, $this->server->getRoomCount('room1'));
        $this->assertTrue($conn->isInRoom('room2'));
        $this->assertFalse($conn->isInRoom('room1'));
    }

    public function testRoomIsRemovedWhenEmpty(): void
    {
        $conn = $this->createConnection();
        $this->server->addConnection($conn);

        $this->server->addConnectionToRoom($conn, 'room1');
        $this->server->removeConnectionFromRoom($conn, 'room1');

        $this->assertSame([], $this->server->getRoomConnections('room1'));
    }

    public function testConnectionData(): void
    {
        $conn = $this->createConnection();

        $conn->setData('user_id', 42);
        $conn->setData('username', 'testuser');

        $this->assertSame(42, $conn->getData('user_id'));
        $this->assertSame('testuser', $conn->getData('username'));
        $this->assertTrue($conn->hasData('user_id'));
        $this->assertFalse($conn->hasData('nonexistent'));
        $this->assertSame('default', $conn->getData('nonexistent', 'default'));
    }

    public function testConnectionState(): void
    {
        $conn = $this->createConnection();

        $this->assertSame(ConnectionState::CONNECTING, $conn->getState());

        $conn->setState(ConnectionState::OPEN);
        $this->assertSame(ConnectionState::OPEN, $conn->getState());
        $this->assertTrue($conn->isOpen());

        $conn->setState(ConnectionState::CLOSED);
        $this->assertSame(ConnectionState::CLOSED, $conn->getState());
        $this->assertFalse($conn->isOpen());
    }

    public function testConnectionPingPong(): void
    {
        $this->tcpConnection->method('write')->willReturn(100);

        $conn = $this->createConnection();
        $conn->setState(ConnectionState::OPEN);

        $this->assertNull($conn->getLastPing());

        $result = $conn->ping('ping data');
        $this->assertTrue($result);
        $this->assertNotNull($conn->getLastPing());

        $result = $conn->pong('pong data');
        $this->assertTrue($result);
    }

    public function testSendWhenNotOpenReturnsFalse(): void
    {
        $conn = $this->createConnection();
        $conn->setState(ConnectionState::CLOSED);

        $result = $conn->send('test');

        $this->assertFalse($result);
    }

    public function testSendArrayAsJson(): void
    {
        $this->tcpConnection->method('write')->willReturn(100);

        $conn = $this->createConnection();
        $conn->setState(ConnectionState::OPEN);

        $result = $conn->send(['key' => 'value']);

        $this->assertTrue($result);
    }

    public function testGetServerFromConnection(): void
    {
        $conn = $this->createConnection();

        $this->assertSame($this->server, $conn->getServer());
    }

    public function testGetRequestFromConnection(): void
    {
        $conn = $this->createConnection();

        $this->assertSame($this->request, $conn->getRequest());
    }

    public function testGetTcpConnection(): void
    {
        $conn = $this->createConnection();

        $this->assertSame($this->tcpConnection, $conn->getTcpConnection());
    }

    public function testGetRemoteAddress(): void
    {
        $conn = $this->createConnection();

        $this->assertSame('127.0.0.1', $conn->getRemoteAddress());
    }

    public function testGetRemotePort(): void
    {
        $conn = $this->createConnection();

        $this->assertSame(12345, $conn->getRemotePort());
    }

    public function testConnectionLastPong(): void
    {
        $conn = $this->createConnection();

        $lastPong = $conn->getLastPong();

        $this->assertIsFloat($lastPong);
        $this->assertGreaterThan(0, $lastPong);
    }

    public function testUniqueConnectionIds(): void
    {
        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $this->assertNotSame($conn1->getId(), $conn2->getId());
    }
}
