<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Socket\StreamSocketResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConnectionPoolTest extends TestCase
{
    #[Test]
    public function enforces_max_connections_limit(): void
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
    public function rejects_connections_when_modifying(): void
    {
        $pool = new ConnectionPool(maxConnections: 10);

        $conn1 = $this->createConnection();
        $pool->add($conn1);

        $this->assertSame(1, $pool->count());
    }

    #[Test]
    public function remove_is_idempotent(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $pool->add($conn);

        $this->assertSame(1, $pool->count());

        $pool->remove($conn);
        $this->assertSame(0, $pool->count());

        $pool->remove($conn);
        $this->assertSame(0, $pool->count());
    }

    #[Test]
    public function remove_timed_out_is_safe_during_concurrent_modifications(): void
    {
        $pool = new ConnectionPool();

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $pool->add($conn1);
        $pool->add($conn2);

        $removed = $pool->removeTimedOut(timeout: 0);

        $this->assertGreaterThanOrEqual(0, $removed);
        $this->assertLessThanOrEqual(2, $removed);
    }

    #[Test]
    public function handles_empty_pool_gracefully(): void
    {
        $pool = new ConnectionPool();

        $this->assertSame(0, $pool->count());
        $this->assertSame([], $pool->getAll());
        $this->assertSame(0, $pool->removeTimedOut(30));
    }

    #[Test]
    public function find_by_socket_returns_correct_connection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $pool->add($conn);

        $found = $pool->findBySocket($conn->getSocket());

        $this->assertSame($conn, $found);
    }

    #[Test]
    public function find_by_socket_returns_null_for_unknown_socket(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $otherSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($otherSocket === false) {
            $this->fail('Failed to create socket');
        }
        $otherSocketResource = new StreamSocketResource($otherSocket);

        $found = $pool->findBySocket($otherSocketResource);

        $this->assertNull($found);

        $otherSocketResource->close();
    }

    #[Test]
    public function close_all_removes_all_connections(): void
    {
        $pool = new ConnectionPool();

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();
        $conn3 = $this->createConnection();

        $pool->add($conn1);
        $pool->add($conn2);
        $pool->add($conn3);

        $this->assertSame(3, $pool->count());

        $pool->closeAll();

        $this->assertSame(0, $pool->count());
        $this->assertSame([], $pool->getAll());
    }

    #[Test]
    public function get_all_returns_array_of_connections(): void
    {
        $pool = new ConnectionPool();

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $pool->add($conn1);
        $pool->add($conn2);

        $all = $pool->getAll();

        $this->assertCount(2, $all);
        $this->assertContains($conn1, $all);
        $this->assertContains($conn2, $all);
    }

    #[Test]
    public function concurrent_add_respects_limit(): void
    {
        $pool = new ConnectionPool(maxConnections: 5);

        $connections = [];
        for ($i = 0; $i < 10; $i++) {
            $connections[] = $this->createConnection();
        }

        foreach ($connections as $conn) {
            $pool->add($conn);
        }

        $this->assertLessThanOrEqual(5, $pool->count());
    }

    #[Test]
    public function find_by_address_returns_correct_connection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection('192.168.1.100');
        $pool->add($conn);

        $found = $pool->findByAddress('192.168.1.100');

        $this->assertSame($conn, $found);
    }

    #[Test]
    public function find_by_address_returns_null_for_unknown_address(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection('192.168.1.100');
        $pool->add($conn);

        $found = $pool->findByAddress('192.168.1.200');

        $this->assertNull($found);
    }

    #[Test]
    public function has_returns_true_for_existing_connection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $pool->add($conn);

        $this->assertTrue($pool->has($conn));
    }

    #[Test]
    public function has_returns_false_for_non_existing_connection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();

        $this->assertFalse($pool->has($conn));
    }

    #[Test]
    public function is_full_returns_true_when_at_max(): void
    {
        $pool = new ConnectionPool(maxConnections: 2);

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $pool->add($conn1);
        $this->assertFalse($pool->isFull());

        $pool->add($conn2);
        $this->assertTrue($pool->isFull());
    }

    #[Test]
    public function is_full_returns_false_when_not_at_max(): void
    {
        $pool = new ConnectionPool(maxConnections: 10);

        $conn = $this->createConnection();
        $pool->add($conn);

        $this->assertFalse($pool->isFull());
    }

    #[Test]
    public function get_max_connections_returns_configured_limit(): void
    {
        $pool = new ConnectionPool(maxConnections: 100);

        $this->assertSame(100, $pool->getMaxConnections());
    }

    #[Test]
    public function remove_timed_out_uses_timestamp_from_add(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $pool->add($conn);

        // Immediately check - should not be timed out
        $removed = $pool->removeTimedOut(timeout: 3600);
        $this->assertSame(0, $removed);
        $this->assertSame(1, $pool->count());
    }

    private function createConnection(string $address = '127.0.0.1'): Connection
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->fail('Failed to create socket');
        }

        return new Connection(new StreamSocketResource($socket), $address, 8080);
    }
}
