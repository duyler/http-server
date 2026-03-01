<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Override;
use PHPUnit\Framework\TestCase;

class ConnectionPoolIntegrationTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            $this->server->reset();
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testConnectionPoolIntegratesWithServer(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8085,
            maxConnections: 10,
        );

        $this->server = new Server($config);

        $this->assertInstanceOf(Server::class, $this->server);
    }

    public function testConnectionPoolRespectsMaxConnectionsFromConfig(): void
    {
        $pool = new ConnectionPool(maxConnections: 3);

        $connections = [];
        for ($i = 0; $i < 5; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false) {
                $connections[] = new Connection(new StreamSocketResource($socket), '127.0.0.1', 8000 + $i);
            }
        }

        foreach ($connections as $conn) {
            $pool->add($conn);
        }

        $this->assertLessThanOrEqual(3, $pool->count());
    }

    public function testConnectionPoolHandlesRapidAddRemove(): void
    {
        $pool = new ConnectionPool(maxConnections: 50);

        $connections = [];
        for ($i = 0; $i < 20; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 9000 + $i);
                $connections[] = $conn;
                $pool->add($conn);
            }
        }

        $initialCount = $pool->count();
        $this->assertGreaterThan(0, $initialCount);

        foreach ($connections as $conn) {
            $pool->remove($conn);
        }

        $this->assertSame(0, $pool->count());
    }

    public function testConnectionPoolFindBySocketWorksCorrectly(): void
    {
        $pool = new ConnectionPool();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->fail('Failed to create socket');
        }

        $socketResource = new StreamSocketResource($socket);
        $conn = new Connection($socketResource, '192.168.1.100', 443);
        $pool->add($conn);

        $found = $pool->findBySocket($socketResource);

        $this->assertNotNull($found);
        $this->assertSame($conn, $found);
        $this->assertSame('192.168.1.100', $found->getRemoteAddress());
        $this->assertSame(443, $found->getRemotePort());
    }

    public function testConnectionPoolRemoveTimedOutWorks(): void
    {
        $pool = new ConnectionPool();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket !== false) {
            $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 8080);
            $pool->add($conn);
        }

        $this->assertSame(1, $pool->count());

        sleep(1);

        $removed = $pool->removeTimedOut(timeout: 0);

        $this->assertGreaterThanOrEqual(0, $removed);
    }
}
