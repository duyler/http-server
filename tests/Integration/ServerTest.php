<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase
{
    private Server $server;
    private int $port;

    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    #[Test]
    public function starts_and_stops_server(): void
    {
        $this->server->start();

        $this->assertFalse($this->server->hasRequest());

        $this->server->stop();

        $this->assertTrue(true);
    }

    #[Test]
    public function receives_get_request(): void
    {
        $this->server->start();

        $this->sendHttpRequest("GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest());

        $request = $this->server->getRequest();

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getUri()->getPath());
    }

    #[Test]
    public function sends_response(): void
    {
        $this->server->start();

        $client = $this->createClient();
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        if ($this->server->hasRequest()) {
            $request = $this->server->getRequest();

            $response = new Response(200, ['Content-Type' => 'text/plain'], 'Hello World');
            $this->server->respond($response);

            $responseData = fread($client, 8192);

            $this->assertStringContainsString('HTTP/1.1 200 OK', $responseData);
            $this->assertStringContainsString('Hello World', $responseData);
        }

        fclose($client);
    }

    #[Test]
    public function handles_multiple_requests(): void
    {
        $this->server->start();

        $client1 = $this->createClient();
        fwrite($client1, "GET /first HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

        usleep(100000);

        if ($this->server->hasRequest()) {
            $request = $this->server->getRequest();
            $response = new Response(200, [], 'First');
            $this->server->respond($response);

            usleep(50000);

            fwrite($client1, "GET /second HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(100000);

            if ($this->server->hasRequest()) {
                $request = $this->server->getRequest();
                $response = new Response(200, [], 'Second');
                $this->server->respond($response);
            }
        }

        fclose($client1);

        $this->assertTrue(true);
    }

    private function sendHttpRequest(string $request): void
    {
        $client = $this->createClient();
        fwrite($client, $request);
        fclose($client);
    }

    /**
     * @return resource
     */
    private function createClient()
    {
        $client = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1,
        );

        if ($client === false) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

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
