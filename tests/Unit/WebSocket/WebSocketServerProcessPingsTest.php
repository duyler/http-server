<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Connection\ConnectionInterface as TcpConnection;
use Duyler\HttpServer\WebSocket\Connection;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

class WebSocketServerProcessPingsTest extends TestCase
{
    private WebSocketServer $server;

    /** @var TcpConnection */
    private TcpConnection $tcpConnection;

    private ServerRequestInterface $request;

    #[Override]
    protected function setUp(): void
    {
        $this->tcpConnection = $this->createStub(TcpConnection::class);
        $this->tcpConnection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $this->tcpConnection->method('getRemotePort')->willReturn(12345);
        $this->tcpConnection->method('write')->willReturn(100);
        $this->request = new ServerRequest('GET', '/ws');
    }

    private function createConnection(): Connection
    {
        return new Connection($this->tcpConnection, $this->request, $this->server);
    }

    private function setLastPing(Connection $conn, ?float $value): void
    {
        $prop = new ReflectionProperty($conn, 'lastPing');
        $prop->setValue($conn, $value);
    }

    private function setLastPong(Connection $conn, float $value): void
    {
        $prop = new ReflectionProperty($conn, 'lastPong');
        $prop->setValue($conn, $value);
    }

    #[Test]
    public function process_pings_closes_connection_on_pong_timeout(): void
    {
        $config = new WebSocketConfig(pingInterval: 30, pongTimeout: 1, autoPing: true);
        $this->server = new WebSocketServer($config);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Connection pong timeout',
                $this->callback(fn(array $ctx) => isset($ctx['conn_id'], $ctx['last_ping'], $ctx['last_pong'])),
            );
        $this->server->setLogger($logger);

        $conn = $this->createConnection();
        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);

        $now = microtime(true);
        $this->setLastPing($conn, $now - 10);
        $this->setLastPong($conn, $now - 15);

        $this->server->processPings();

        $this->assertSame(ConnectionState::CLOSING, $conn->getState());
    }

    #[Test]
    public function process_pings_sends_ping_when_interval_exceeded(): void
    {
        $config = new WebSocketConfig(pingInterval: 1, pongTimeout: 10, autoPing: true);
        $this->server = new WebSocketServer($config);

        $conn = $this->createConnection();
        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);

        $now = microtime(true);
        $this->setLastPing($conn, $now - 5);
        $this->setLastPong($conn, $now);

        $this->server->processPings();

        $this->assertNotNull($conn->getLastPing());
        $this->assertGreaterThan($now - 1, $conn->getLastPing());
    }

    #[Test]
    public function process_pings_does_not_send_ping_when_recent(): void
    {
        $config = new WebSocketConfig(pingInterval: 300, pongTimeout: 60, autoPing: true);
        $this->server = new WebSocketServer($config);

        $conn = $this->createConnection();
        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);

        $now = microtime(true);
        $lastPing = $now - 1;
        $this->setLastPing($conn, $lastPing);
        $this->setLastPong($conn, $now);

        $this->server->processPings();

        $this->assertSame($lastPing, $conn->getLastPing());
    }

    #[Test]
    public function process_pings_does_not_close_when_pong_received_within_timeout(): void
    {
        $config = new WebSocketConfig(pingInterval: 1, pongTimeout: 60, autoPing: true);
        $this->server = new WebSocketServer($config);

        $conn = $this->createConnection();
        $conn->setState(ConnectionState::OPEN);
        $this->server->addConnection($conn);

        $now = microtime(true);
        $this->setLastPing($conn, $now - 5);
        $this->setLastPong($conn, $now - 2);

        $this->server->processPings();

        $this->assertSame(ConnectionState::OPEN, $conn->getState());
    }
}
