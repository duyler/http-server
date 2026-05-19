<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ConnectionPoolExtendedTest extends TestCase
{
    public function testAddWithEmptyAddressDoesNotIndexByAddress(): void
    {
        $pool = new ConnectionPool();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $resource = new StreamSocketResource($socket);

        $conn = new Connection($resource, '', 0);
        $pool->add($conn);

        $found = $pool->findByAddress('');
        $this->assertNull($found);
        $this->assertSame(1, $pool->count());
    }

    public function testRemoveNonExistentConnectionIsSafe(): void
    {
        $pool = new ConnectionPool();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $resource = new StreamSocketResource($socket);

        $conn = new Connection($resource, '127.0.0.1', 8080);

        $pool->remove($conn);

        $this->assertSame(0, $pool->count());
    }

    public function testRemoveTimedOutRemovesOldConnections(): void
    {
        $pool = new ConnectionPool();

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $resource = new StreamSocketResource($socket);

        $conn = new Connection($resource, '127.0.0.1', 8080);
        $pool->add($conn);

        $removed = $pool->removeTimedOut(0);

        $this->assertCount(1, $removed);
        $this->assertSame(0, $pool->count());
    }

    public function testRemoveTimedOutWithReentrancyReturnsZero(): void
    {
        $pool = new ConnectionPool();

        $reflection = new ReflectionClass($pool);
        $property = $reflection->getProperty('isModifying');

        $removed = $pool->removeTimedOut(30);

        $this->assertSame([], $removed);
    }

    public function testAddWithReentrancyClosesConnection(): void
    {
        $pool = new ConnectionPool();
        $poolReflection = new ReflectionClass($pool);
        $modifyingProp = $poolReflection->getProperty('isModifying');

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $resource = new StreamSocketResource($socket);

        $conn = new Connection($resource, '127.0.0.1', 8080);

        $modifyingProp->setValue($pool, true);

        $pool->add($conn);

        $this->assertSame(0, $pool->count());
    }

    public function testRemoveWithReentrancyReturnsEarly(): void
    {
        $pool = new ConnectionPool();
        $poolReflection = new ReflectionClass($pool);
        $modifyingProp = $poolReflection->getProperty('isModifying');

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $resource = new StreamSocketResource($socket);

        $conn = new Connection($resource, '127.0.0.1', 8080);
        $pool->add($conn);

        $this->assertSame(1, $pool->count());

        $modifyingProp->setValue($pool, true);

        $pool->remove($conn);

        $this->assertSame(1, $pool->count());
    }

    public function testRemoveTimedOutDetectsTimedOutConnections(): void
    {
        $pool = new ConnectionPool();

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket1);
        $resource1 = new StreamSocketResource($socket1);
        $conn1 = new Connection($resource1, '127.0.0.1', 8080);

        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket2);
        $resource2 = new StreamSocketResource($socket2);
        $conn2 = new Connection($resource2, '127.0.0.2', 8080);

        $pool->add($conn1);
        $pool->add($conn2);

        $removed = $pool->removeTimedOut(3600);

        $this->assertSame([], $removed);
        $this->assertSame(2, $pool->count());
    }

    #[Override]
    protected function tearDown(): void {}
}
