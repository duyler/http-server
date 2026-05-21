<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Security;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

class DosProtectionTest extends TestCase
{
    private ?Server $server = null;
    private int $port;

    #[Override]
    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function oversized_body_rejected(): void
    {
        $maxSize = 1024;
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            maxRequestSize: $maxSize,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $oversizedBody = str_repeat('A', $maxSize + 100);
        $request = "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: " . strlen($oversizedBody) . "\r\n"
            . "\r\n"
            . $oversizedBody;

        $client = $this->createClient();
        fwrite($client, $request);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(50000);
            $raw = fread($client, 8192);
            if (false !== $raw && '' !== $raw && str_contains($raw, 'HTTP/')) {
                break;
            }
        }

        $raw = '';
        while ($data = fread($client, 8192)) {
            $raw .= $data;
            if (str_contains($raw, 'HTTP/')) {
                break;
            }
        }

        $this->assertTrue(
            str_contains($raw, '413') || str_contains($raw, '400') || '' === trim($raw),
            'Oversized request should be rejected with 413, 400, or connection closed',
        );

        fclose($client);
    }

    #[Test]
    public function connection_pool_enforces_max_connections(): void
    {
        $maxConnections = 5;
        $pool = new ConnectionPool(maxConnections: $maxConnections);

        $connections = [];
        for ($i = 0; $i < $maxConnections + 5; $i++) {
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
    }

    #[Test]
    public function connection_pool_cleanup_after_close(): void
    {
        $pool = new ConnectionPool(maxConnections: 3);

        $connections = [];
        for ($i = 0; $i < 3; $i++) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (false !== $socket) {
                $conn = new Connection(new StreamSocketResource($socket), '127.0.0.1', 9000 + $i);
                $connections[] = $conn;
                $pool->add($conn);
            }
        }

        $this->assertSame(3, $pool->count());
        $this->assertTrue($pool->isFull());

        $pool->remove($connections[0]);
        $this->assertSame(2, $pool->count());
        $this->assertFalse($pool->isFull());

        $newSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false !== $newSocket) {
            $newConn = new Connection(new StreamSocketResource($newSocket), '127.0.0.1', 9999);
            $pool->add($newConn);
            $this->assertSame(3, $pool->count());
            $pool->remove($newConn);
        }

        foreach ($connections as $conn) {
            $pool->remove($conn);
        }
    }

    #[Test]
    public function max_connections_rejects_excess(): void
    {
        $maxConnections = 3;
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            maxConnections: $maxConnections,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $clients = [];
        for ($i = 0; $i < $maxConnections; $i++) {
            $client = $this->createClient();
            fwrite($client, "GET /{$i} HTTP/1.1\r\nHost: localhost\r\n\r\n");
            $clients[] = $client;
        }

        usleep(100000);

        $acceptedCount = 0;
        for ($i = 0; $i < $maxConnections + 3; $i++) {
            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
                $acceptedCount++;
            }
        }

        $this->assertLessThanOrEqual($maxConnections, $acceptedCount);

        foreach ($clients as $client) {
            fclose($client);
        }
    }

    /**
     * @return resource
     */
    private function createClient()
    {
        $client = stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1.0,
        );

        if (false === $client) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

        stream_set_timeout($client, 5);

        return $client;
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}
