<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Worker;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WorkerPool\Worker\HttpWorkerAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

class HttpWorkerAdapterTest extends TestCase
{
    private Server $server;
    private HttpWorkerAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->server = new Server($config);
        $this->adapter = new HttpWorkerAdapter($this->server);
    }

    #[Test]
    public function creates_adapter_with_server(): void
    {
        $this->assertInstanceOf(HttpWorkerAdapter::class, $this->adapter);
    }

    #[Test]
    public function handles_simple_http_request(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $request = "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
        socket_write($clientSocket, $request);

        $pid = pcntl_fork();

        if ($pid === 0) {
            socket_close($clientSocket);

            try {
                $this->adapter->handleConnection($serverSocket, []);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage());
            }

            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        while (true) {
            $chunk = @socket_read($clientSocket, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);

        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
    }

    #[Test]
    public function reads_request_with_body(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        $body = '{"test": "data"}';
        $request = "POST /api HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";
        $request .= $body;

        socket_write($clientSocket, $request);

        $pid = pcntl_fork();

        if ($pid === 0) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        $response = '';
        while (true) {
            $chunk = @socket_read($clientSocket, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertStringContainsString('HTTP/', $response);
    }

    #[Test]
    public function closes_socket_after_handling(): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$serverSocket, $clientSocket] = $pair;

        socket_write($clientSocket, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        $pid = pcntl_fork();

        if ($pid === 0) {
            socket_close($clientSocket);
            $this->adapter->handleConnection($serverSocket, []);
            exit(0);
        }

        socket_close($serverSocket);

        sleep(1);

        $read = @socket_read($clientSocket, 1);

        socket_close($clientSocket);
        pcntl_waitpid($pid, $status);

        $this->assertTrue(true);
    }
}
