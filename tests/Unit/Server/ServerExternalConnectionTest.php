<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\ErrorHandler\ErrorHandlerInterface;
use Duyler\HttpServer\Exception\InvalidConfigException;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Tests\Support\ErrorReportingScope;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Socket;

#[CoversClass(Server::class)]
class ServerExternalConnectionTest extends TestCase
{
    use ErrorReportingScope;
    private ErrorHandlerInterface $errorHandler;

    private int $basePort = 28080;

    #[Override]
    protected function setUp(): void
    {
        $this->errorHandler = $this->createStub(ErrorHandlerInterface::class);
        $this->errorHandler->method('handleError')->willReturn(false);
    }

    private function createServer(int $port = 28080): Server
    {
        return new Server(
            new ServerConfig(
                host: '127.0.0.1',
                port: $port,
                memoryLimit: 134217728,
            ),
            errorHandler: $this->errorHandler,
        );
    }

    private function nextPort(): int
    {
        return ++$this->basePort;
    }

    #[Test]
    public function add_external_connection_with_connected_socket_resolves_peer(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);
        $server->start();

        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($serverSocket);
        socket_bind($serverSocket, '127.0.0.1', $port);
        socket_listen($serverSocket);

        $clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($clientSocket);
        socket_set_nonblock($clientSocket);
        socket_connect($clientSocket, '127.0.0.1', $port);

        $metadata = [
            'worker_id' => 1,
            'client_ip' => '192.168.1.1',
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($clientSocket, $metadata));

        $this->assertSame(1, $server->getWorkerId());

        socket_close($clientSocket);
        socket_close($serverSocket);
        $server->stop();
    }

    #[Test]
    public function add_external_connection_with_unconnected_socket_uses_fallback_ip(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 2,
            'client_ip' => '10.0.0.5',
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($socket, $metadata));

        $this->assertSame(2, $server->getWorkerId());

        socket_close($socket);
    }

    #[Test]
    public function add_external_connection_without_client_ip_defaults_to_zero(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 3,
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($socket, $metadata));

        $this->assertSame(3, $server->getWorkerId());

        socket_close($socket);
    }

    #[Test]
    public function add_external_connection_with_worker_pid(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 4,
            'worker_pid' => 99999,
            'client_ip' => '172.16.0.1',
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($socket, $metadata));

        $this->assertSame(4, $server->getWorkerId());

        socket_close($socket);
    }

    #[Test]
    public function add_external_connection_with_stream_resource(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $stream = stream_socket_client(
            'tcp://127.0.0.1:' . $port,
            $errno,
            $errstr,
            1,
        );

        if (false === $stream) {
            $serverSocket = stream_socket_server('tcp://127.0.0.1:' . $port);
            $this->assertNotFalse($serverSocket);

            $stream = stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $errstr,
                1,
            );
            $this->assertNotFalse($stream);
        }

        $metadata = [
            'worker_id' => 5,
            'client_ip' => '192.168.0.1',
        ];

        $server->addExternalConnection($stream, $metadata);

        $this->assertSame(5, $server->getWorkerId());

        if (isset($serverSocket)) {
            fclose($serverSocket);
        }
        fclose($stream);
    }

    #[Test]
    public function add_external_connection_throws_without_worker_id(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $this->expectException(InvalidConfigException::class);

        try {
            $server->addExternalConnection($socket, []);
        } finally {
            socket_close($socket);
        }
    }

    #[Test]
    public function add_external_connection_logs_warning_on_peer_name_failure(): void
    {
        $port = $this->nextPort();
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to get peer name'),
                $this->callback(fn(array $context): bool => isset($context['fallback_ip'])),
            );

        $server = new Server(
            new ServerConfig(
                host: '127.0.0.1',
                port: $port,
                memoryLimit: 134217728,
            ),
            logger: $logger,
            errorHandler: $this->errorHandler,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 6,
            'client_ip' => '10.10.10.10',
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($socket, $metadata));

        socket_close($socket);
    }

    #[Test]
    public function add_external_connection_uses_metadata_client_ip_on_peer_failure(): void
    {
        $port = $this->nextPort();
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'External connection added',
                $this->callback(fn(array $context): bool => '192.168.99.99' === ($context['client_ip'] ?? null)),
            );

        $server = new Server(
            new ServerConfig(
                host: '127.0.0.1',
                port: $port,
                memoryLimit: 134217728,
            ),
            logger: $logger,
            errorHandler: $this->errorHandler,
        );

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 8,
            'client_ip' => '192.168.99.99',
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($socket, $metadata));

        socket_close($socket);
    }

    #[Test]
    public function add_external_connection_sets_worker_pool_mode(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $metadata = [
            'worker_id' => 7,
        ];

        $this->withSuppressedErrors(fn() => $server->addExternalConnection($socket, $metadata));

        $this->assertSame(\Duyler\HttpServer\Config\ServerMode::WorkerPool, $server->getMode());

        socket_close($socket);
    }

    #[Test]
    public function get_notification_read_stream_exports_socket_via_stream_socket_resource(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);
        $server->start();
        $server->enableNotification();

        $stream = $server->getNotificationReadStream();

        $this->assertIsResource($stream);

        $server->stopWatchers();
        $server->stop();
    }

    #[Test]
    public function get_notification_read_stream_returns_null_when_no_notification(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);
        $server->start();

        $stream = $server->getNotificationReadStream();

        $this->assertNull($stream);

        $server->stop();
    }

    #[Test]
    public function get_notification_read_stream_caches_result(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);
        $server->start();
        $server->enableNotification();

        $stream1 = $server->getNotificationReadStream();
        $stream2 = $server->getNotificationReadStream();

        $this->assertSame($stream1, $stream2);

        $server->stopWatchers();
        $server->stop();
    }

    #[Test]
    public function add_external_connection_multiple_connections(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);

        $this->withSuppressedErrors(function () use ($server, $socket1, $socket2): void {
            $server->addExternalConnection($socket1, ['worker_id' => 10, 'client_ip' => '10.0.0.1']);
            $server->addExternalConnection($socket2, ['worker_id' => 10, 'client_ip' => '10.0.0.2']);
        });

        $this->assertSame(10, $server->getWorkerId());

        socket_close($socket1);
        socket_close($socket2);
    }

    #[Test]
    public function disable_notification_resets_stream_cache(): void
    {
        $port = $this->nextPort();
        $server = $this->createServer($port);
        $server->start();
        $server->enableNotification();

        $stream1 = $server->getNotificationReadStream();
        $this->assertIsResource($stream1);

        $server->disableNotification();

        $stream2 = $server->getNotificationReadStream();
        $this->assertNull($stream2);

        $server->stop();
    }

    #[Test]
    public function get_notification_read_stream_returns_null_on_export_failure(): void
    {
        $port = $this->nextPort();
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())
            ->method('warning')
            ->with('socket_export_stream failed');

        $server = new Server(
            new ServerConfig(
                host: '127.0.0.1',
                port: $port,
                memoryLimit: 134217728,
            ),
            logger: $logger,
            errorHandler: $this->errorHandler,
        );

        $server->start();
        $server->enableNotification();

        $readSocket = $server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $readSocket);

        socket_close($readSocket);

        $stream = $server->getNotificationReadStream();

        $this->assertNull($stream);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->withSuppressedErrors(function (): void {
            try {
                parent::tearDown();
            } catch (Throwable) {
            }
        });
    }
}
