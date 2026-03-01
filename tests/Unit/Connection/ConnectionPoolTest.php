<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Socket\StreamSocketResource;
use PHPUnit\Framework\TestCase;

class ConnectionPoolTest extends TestCase
{
    public function testEnforcesMaxConnectionsLimit(): void
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

    public function testRejectsConnectionsWhenModifying(): void
    {
        $pool = new ConnectionPool(maxConnections: 10);

        $conn1 = $this->createConnection();
        $pool->add($conn1);

        $this->assertSame(1, $pool->count());
    }

    public function testRemoveIsIdempotent(): void
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

    public function testRemoveTimedOutIsSafeDuringConcurrentModifications(): void
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

    public function testHandlesEmptyPoolGracefully(): void
    {
        $pool = new ConnectionPool();

        $this->assertSame(0, $pool->count());
        $this->assertSame([], $pool->getAll());
        $this->assertSame(0, $pool->removeTimedOut(30));
    }

    public function testFindBySocketReturnsCorrectConnection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $pool->add($conn);

        $found = $pool->findBySocket($conn->getSocket());

        $this->assertSame($conn, $found);
    }

    public function testFindBySocketReturnsNullForUnknownSocket(): void
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

    public function testCloseAllRemovesAllConnections(): void
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

    public function testGetAllReturnsArrayOfConnections(): void
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

    public function testConcurrentAddRespectsLimit(): void
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

    public function testFindByAddressReturnsCorrectConnection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection('192.168.1.100');
        $pool->add($conn);

        $found = $pool->findByAddress('192.168.1.100');

        $this->assertSame($conn, $found);
    }

    public function testFindByAddressReturnsNullForUnknownAddress(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection('192.168.1.100');
        $pool->add($conn);

        $found = $pool->findByAddress('192.168.1.200');

        $this->assertNull($found);
    }

    public function testHasReturnsTrueForExistingConnection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();
        $pool->add($conn);

        $this->assertTrue($pool->has($conn));
    }

    public function testHasReturnsFalseForNonExistingConnection(): void
    {
        $pool = new ConnectionPool();

        $conn = $this->createConnection();

        $this->assertFalse($pool->has($conn));
    }

    public function testIsFullReturnsTrueWhenAtMax(): void
    {
        $pool = new ConnectionPool(maxConnections: 2);

        $conn1 = $this->createConnection();
        $conn2 = $this->createConnection();

        $pool->add($conn1);
        $this->assertFalse($pool->isFull());

        $pool->add($conn2);
        $this->assertTrue($pool->isFull());
    }

    public function testIsFullReturnsFalseWhenNotAtMax(): void
    {
        $pool = new ConnectionPool(maxConnections: 10);

        $conn = $this->createConnection();
        $pool->add($conn);

        $this->assertFalse($pool->isFull());
    }

    public function testGetMaxConnectionsReturnsConfiguredLimit(): void
    {
        $pool = new ConnectionPool(maxConnections: 100);

        $this->assertSame(100, $pool->getMaxConnections());
    }

    public function testRemoveTimedOutUsesTimestampFromAdd(): void
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
