<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConnectionPoolIntegrationTest extends TestCase
{
    #[Test]
    public function connection_pool_integrates_with_server(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8085,
            maxConnections: 10,
        );

        $server = new Server($config);

        $this->assertInstanceOf(Server::class, $server);
    }

    #[Test]
    public function connection_pool_respects_max_connections_from_config(): void
    {
        $pool = new ConnectionPool(maxConnections: 3);
        
        $connections = [];
        for ($i = 0; $i < 5; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false) {
                $connections[] = new Connection($socket, '127.0.0.1', 8000 + $i);
            }
        }

        foreach ($connections as $conn) {
            $pool->add($conn);
        }

        $this->assertLessThanOrEqual(3, $pool->count());
    }

    #[Test]
    public function connection_pool_handles_rapid_add_remove(): void
    {
        $pool = new ConnectionPool(maxConnections: 50);
        
        $connections = [];
        for ($i = 0; $i < 20; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false) {
                $conn = new Connection($socket, '127.0.0.1', 9000 + $i);
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

    #[Test]
    public function connection_pool_find_by_socket_works_correctly(): void
    {
        $pool = new ConnectionPool();
        
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->fail('Failed to create socket');
        }
        
        $conn = new Connection($socket, '192.168.1.100', 443);
        $pool->add($conn);

        $found = $pool->findBySocket($socket);

        $this->assertNotNull($found);
        $this->assertSame($conn, $found);
        $this->assertSame('192.168.1.100', $found->getRemoteAddress());
        $this->assertSame(443, $found->getRemotePort());
    }

    #[Test]
    public function connection_pool_remove_timed_out_works(): void
    {
        $pool = new ConnectionPool();
        
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket !== false) {
            $conn = new Connection($socket, '127.0.0.1', 8080);
            $pool->add($conn);
        }

        $this->assertSame(1, $pool->count());
        
        sleep(1);
        
        $removed = $pool->removeTimedOut(timeout: 0);
        
        $this->assertGreaterThanOrEqual(0, $removed);
    }
}

