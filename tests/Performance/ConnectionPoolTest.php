<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Performance;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Socket\StreamSocketResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('performance')]
#[CoversClass(ConnectionPool::class)]
class ConnectionPoolTest extends TestCase
{
    #[Test]
    public function add_and_remove_at_max_connections(): void
    {
        $maxConnections = 50;
        $pool = new ConnectionPool(maxConnections: $maxConnections);

        $connections = [];
        for ($i = 0; $i < $maxConnections; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (false !== $socket) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 8000 + $i);
                $connections[] = $conn;
                $pool->add($conn);
            }
        }

        $this->assertSame($maxConnections, $pool->count());
        $this->assertTrue($pool->isFull());

        foreach ($connections as $conn) {
            $pool->remove($conn);
        }

        $this->assertSame(0, $pool->count());
    }

    #[Test]
    public function excess_connections_are_rejected_at_max(): void
    {
        $maxConnections = 10;
        $pool = new ConnectionPool(maxConnections: $maxConnections);

        $connections = [];
        for ($i = 0; $i < $maxConnections + 5; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (false !== $socket) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 9000 + $i);
                $connections[] = $conn;
                $pool->add($conn);
            }
        }

        $this->assertSame($maxConnections, $pool->count());
        $this->assertTrue($pool->isFull());

        foreach ($connections as $conn) {
            $pool->remove($conn);
        }
    }

    #[Test]
    public function pool_accepts_new_after_close(): void
    {
        $maxConnections = 5;
        $pool = new ConnectionPool(maxConnections: $maxConnections);

        $firstBatch = [];
        for ($i = 0; $i < $maxConnections; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (false !== $socket) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 10000 + $i);
                $firstBatch[] = $conn;
                $pool->add($conn);
            }
        }

        $this->assertTrue($pool->isFull());

        foreach ($firstBatch as $conn) {
            $pool->remove($conn);
        }

        $this->assertSame(0, $pool->count());
        $this->assertFalse($pool->isFull());

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false !== $socket) {
            $newConn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 5555);
            $pool->add($newConn);
            $this->assertSame(1, $pool->count());
            $pool->remove($newConn);
        }
    }

    #[Test]
    public function rapid_add_remove_cycle_does_not_leak(): void
    {
        $maxConnections = 100;
        $pool = new ConnectionPool(maxConnections: $maxConnections);

        for ($cycle = 0; $cycle < 5; $cycle++) {
            $connections = [];
            for ($i = 0; $i < $maxConnections; $i++) {
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if (false !== $socket) {
                    $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 20000 + $i);
                    $connections[] = $conn;
                    $pool->add($conn);
                }
            }

            $this->assertSame($maxConnections, $pool->count());

            foreach ($connections as $conn) {
                $pool->remove($conn);
            }

            $this->assertSame(0, $pool->count());
        }
    }

    #[Test]
    public function pool_stats_are_accurate(): void
    {
        $maxConnections = 20;
        $pool = new ConnectionPool(maxConnections: $maxConnections);

        $this->assertSame($maxConnections, $pool->getMaxConnections());
        $this->assertSame(0, $pool->count());
        $this->assertFalse($pool->isFull());

        $connections = [];
        for ($i = 0; $i < 10; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (false !== $socket) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 30000 + $i);
                $connections[] = $conn;
                $pool->add($conn);
            }
        }

        $this->assertSame(10, $pool->count());
        $this->assertFalse($pool->isFull());

        foreach ($connections as $conn) {
            $pool->remove($conn);
        }

        $this->assertSame(0, $pool->count());
    }

    #[Test]
    public function close_all_resets_pool(): void
    {
        $pool = new ConnectionPool(maxConnections: 10);

        for ($i = 0; $i < 5; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (false !== $socket) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 40000 + $i);
                $pool->add($conn);
            }
        }

        $this->assertSame(5, $pool->count());

        $pool->closeAll();

        $this->assertSame(0, $pool->count());
        $this->assertFalse($pool->isFull());
    }
}
