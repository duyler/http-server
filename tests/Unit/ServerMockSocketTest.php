<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\ErrorHandler\ErrorHandlerInterface;
use Duyler\HttpServer\Exception\InvalidConfigException;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

class ServerMockSocketTest extends TestCase
{
    private ErrorHandlerInterface&MockObject $errorHandler;

    private ?Server $server = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->errorHandler = $this->createMock(ErrorHandlerInterface::class);
        $this->errorHandler->method('handleError')->willReturn(false);
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
    public function add_external_connection_resolves_ip_from_mock(): void
    {
        $this->server = $this->createServer();

        $mockResource = $this->createMockSocketResource(
            ['ip' => '192.168.1.100', 'port' => 54321],
        );

        $this->server->addExternalConnection($mockResource, [
            'worker_id' => 1,
        ]);

        $pool = $this->getConnectionPool();
        $connections = $pool->getAll();

        $this->assertCount(1, $connections);
        $this->assertSame('192.168.1.100', $connections[0]->getRemoteAddress());
        $this->assertSame(54321, $connections[0]->getRemotePort());
    }

    #[Test]
    public function add_external_connection_falls_back_to_default_ip(): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        );
        $logger->method('debug');

        $this->server = $this->createServer($logger);

        $mockResource = $this->createMockSocketResource(false);

        $this->server->addExternalConnection($mockResource, [
            'worker_id' => 2,
        ]);

        $pool = $this->getConnectionPool();
        $connections = $pool->getAll();

        $this->assertCount(1, $connections);
        $this->assertSame('0.0.0.0', $connections[0]->getRemoteAddress());
        $this->assertSame(0, $connections[0]->getRemotePort());
        $this->assertContains('Failed to get peer name', $warnings);
    }

    #[Test]
    public function add_external_connection_uses_client_ip_from_metadata(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning');
        $logger->method('debug');

        $this->server = $this->createServer($logger);

        $mockResource = $this->createMockSocketResource(false);

        $this->server->addExternalConnection($mockResource, [
            'worker_id' => 3,
            'client_ip' => '10.0.0.5',
        ]);

        $pool = $this->getConnectionPool();
        $connections = $pool->getAll();

        $this->assertCount(1, $connections);
        $this->assertSame('10.0.0.5', $connections[0]->getRemoteAddress());
    }

    #[Test]
    public function add_external_connection_throws_without_worker_id(): void
    {
        $this->server = $this->createServer();

        $mockResource = $this->createMock(SocketResourceInterface::class);

        $this->expectException(InvalidConfigException::class);

        $this->server->addExternalConnection($mockResource, []);
    }

    #[Test]
    public function add_external_connection_sets_worker_context(): void
    {
        $this->server = $this->createServer();

        $mockResource = $this->createMockSocketResource(
            ['ip' => '127.0.0.1', 'port' => 8080],
        );

        $this->server->addExternalConnection($mockResource, [
            'worker_id' => 5,
            'worker_pid' => 12345,
        ]);

        $this->assertSame(5, $this->server->getWorkerId());
        $this->assertSame(ServerMode::WorkerPool, $this->server->getMode());
    }

    #[Test]
    public function add_external_connection_rejects_when_pool_full(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
            maxConnections: 1,
        );

        $this->server = new Server($config, errorHandler: $this->errorHandler);

        $mockResource1 = $this->createMockSocketResource(
            ['ip' => '10.0.0.1', 'port' => 1111],
        );
        $mockResource2 = $this->createMockSocketResource(
            ['ip' => '10.0.0.2', 'port' => 2222],
        );

        $this->server->addExternalConnection($mockResource1, ['worker_id' => 1]);
        $this->server->addExternalConnection($mockResource2, ['worker_id' => 2]);

        $pool = $this->getConnectionPool();

        $this->assertSame(1, $pool->count());

        $connections = $pool->getAll();
        $this->assertSame('10.0.0.1', $connections[0]->getRemoteAddress());
    }

    #[Test]
    public function export_to_stream_returns_stream_from_mock(): void
    {
        $this->server = $this->createServer();

        $stream = fopen('php://memory', 'r+');

        $mockResource = $this->createMock(SocketResourceInterface::class);
        $mockResource->method('exportStream')->willReturn($stream);

        $ref = new ReflectionMethod($this->server, 'exportToStream');

        $result = $ref->invoke($this->server, $mockResource);

        $this->assertIsResource($result);

        fclose($stream);
    }

    #[Test]
    public function export_to_stream_returns_false_on_failure(): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        );
        $logger->method('debug');

        $this->server = $this->createServer($logger);

        $mockResource = $this->createMock(SocketResourceInterface::class);
        $mockResource->method('exportStream')->willReturn(false);

        $ref = new ReflectionMethod($this->server, 'exportToStream');

        $result = $ref->invoke($this->server, $mockResource);

        $this->assertFalse($result);
        $this->assertContains('socket_export_stream failed', $warnings);
    }

    private function createServer(?LoggerInterface $logger = null): Server
    {
        return new Server(
            new ServerConfig(
                host: '127.0.0.1',
                port: 8080,
            ),
            logger: $logger ?? new \Psr\Log\NullLogger(),
            errorHandler: $this->errorHandler,
        );
    }

    private function createMockSocketResource(array|false $peerName): SocketResourceInterface
    {
        $mock = $this->createMock(SocketResourceInterface::class);
        $mock->method('getPeerName')->willReturn($peerName);
        $mock->method('isValid')->willReturn(true);

        return $mock;
    }

    private function getConnectionPool(): ConnectionPool
    {
        $ref = new ReflectionProperty($this->server, 'connectionPool');

        return $ref->getValue($this->server);
    }
}
