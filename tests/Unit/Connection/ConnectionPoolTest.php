<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConnectionPoolTest extends TestCase
{
    private ConnectionPool $pool;

    protected function setUp(): void
    {
        $this->pool = new ConnectionPool(maxConnections: 10);
    }

    #[Test]
    public function starts_empty(): void
    {
        $this->assertSame(0, $this->pool->count());
    }

    #[Test]
    public function adds_connection(): void
    {
        $connection = $this->createConnection();
        $this->pool->add($connection);

        $this->assertSame(1, $this->pool->count());
    }

    #[Test]
    public function removes_connection(): void
    {
        $connection = $this->createConnection();
        $this->pool->add($connection);
        $this->pool->remove($connection);

        $this->assertSame(0, $this->pool->count());
    }

    #[Test]
    public function finds_connection_by_socket(): void
    {
        $socket = fopen('php://memory', 'r+');
        $connection = new Connection($socket, '127.0.0.1', 8080);
        $this->pool->add($connection);

        $found = $this->pool->findBySocket($socket);

        $this->assertSame($connection, $found);

        fclose($socket);
    }

    #[Test]
    public function returns_null_for_unknown_socket(): void
    {
        $socket = fopen('php://memory', 'r+');

        $found = $this->pool->findBySocket($socket);

        $this->assertNull($found);

        fclose($socket);
    }

    #[Test]
    public function returns_all_connections(): void
    {
        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $this->pool->add($conn1);
        $this->pool->add($conn2);

        $all = $this->pool->getAll();

        $this->assertCount(2, $all);
        $this->assertContains($conn1, $all);
        $this->assertContains($conn2, $all);
    }

    #[Test]
    public function respects_max_connections_limit(): void
    {
        $pool = new ConnectionPool(maxConnections: 2);

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();
        $conn3 = $this->createConnection();

        $pool->add($conn1);
        $pool->add($conn2);
        $pool->add($conn3);

        $this->assertSame(2, $pool->count());
    }

    #[Test]
    public function removes_timed_out_connections(): void
    {
        $connection = $this->createConnection();
        $this->pool->add($connection);

        sleep(2);

        $removed = $this->pool->removeTimedOut(1);

        $this->assertSame(1, $removed);
        $this->assertSame(0, $this->pool->count());
    }

    #[Test]
    public function does_not_remove_active_connections(): void
    {
        $connection = $this->createConnection();
        $this->pool->add($connection);

        $removed = $this->pool->removeTimedOut(10);

        $this->assertSame(0, $removed);
        $this->assertSame(1, $this->pool->count());
    }

    #[Test]
    public function closes_all_connections(): void
    {
        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $this->pool->add($conn1);
        $this->pool->add($conn2);

        $this->pool->closeAll();

        $this->assertSame(0, $this->pool->count());
    }

    private function createConnection(): Connection
    {
        $socket = fopen('php://memory', 'r+');
        return new Connection($socket, '127.0.0.1', rand(1024, 65535));
    }
}
