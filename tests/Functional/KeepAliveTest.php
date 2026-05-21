<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class KeepAliveTest extends TestCase
{
    private ?Server $server = null;
    private int $port;

    #[Override]
    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
            enableKeepAlive: true,
            keepAliveTimeout: 30,
            keepAliveMaxRequests: 5,
        );

        $this->server = new Server($config);
        $this->server->start();
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
    public function multiple_requests_per_single_connection(): void
    {
        $client = $this->createClient();

        for ($i = 0; $i < 3; $i++) {
            fwrite($client, "GET /keep-alive/{$i} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            usleep(100000);

            $this->assertTrue($this->server->hasRequest(), "Server should have keep-alive request on iteration {$i}");

            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame("/keep-alive/{$i}", $requestData->request->getUri()->getPath());

            $response = new Response(200, [], "Response {$i}");
            $this->server->respond(new ResponseData($requestData->id, $response));

            usleep(50000);

            $chunk = fread($client, 8192);
            $this->assertStringContainsString("Response {$i}", $chunk);
        }

        fclose($client);
    }

    #[Test]
    public function keep_alive_header_in_response(): void
    {
        $client = $this->createClient();
        fwrite($client, "GET /keepalive-header HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received keep-alive header request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);

        $response = new Response(200, ['Connection' => 'keep-alive'], 'Keep-Alive test');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('keep-alive', strtolower($raw));
    }

    #[Test]
    public function connection_close_after_max_requests(): void
    {
        $maxRequests = 5;

        $port = $this->findAvailablePort();
        $server = $this->createServerWithMaxRequests($port, $maxRequests);

        $client = $this->connectToPort($port);

        for ($i = 0; $i < $maxRequests; $i++) {
            fwrite($client, "GET /max/{$i} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            usleep(100000);

            $this->assertTrue($server->hasRequest(), "Server should have received request {$i}");

            $requestData = $server->getRequest();
            $this->assertNotNull($requestData);

            $response = new Response(200, [], "Max {$i}");
            $server->respond(new ResponseData($requestData->id, $response));
            usleep(50000);
            fread($client, 8192);
        }

        fwrite($client, "GET /max/after HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(200000);

        $hasRequestAfterMax = $server->hasRequest();

        fclose($client);

        try {
            $server->stop();
            $server->reset();
        } catch (Throwable) {
        }

        $this->assertFalse($hasRequestAfterMax, 'Server should close connection after max keep-alive requests');
    }

    #[Test]
    public function connection_close_after_explicit_close_header(): void
    {
        $client = $this->createClient();
        fwrite($client, "GET /close HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received close header request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);

        $response = new Response(200, [], 'Closing');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('200 OK', $raw);
    }

    #[Test]
    public function sequential_get_post_on_same_connection(): void
    {
        $client = $this->createClient();

        fwrite($client, "GET /first HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received GET request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('GET', $requestData->request->getMethod());

        $response = new Response(200, [], 'GET response');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);
        fread($client, 8192);

        $body = '{"data":"test"}';
        fwrite($client, "POST /second HTTP/1.1\r\nHost: localhost\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: keep-alive\r\n\r\n" . $body);

        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received POST request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('POST', $requestData->request->getMethod());
        $this->assertSame($body, (string) $requestData->request->getBody());

        $response = new Response(200, [], 'POST response');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);
        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('POST response', $raw);
    }

    private function createServerWithMaxRequests(int $port, int $maxRequests): Server
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $port,
            requestTimeout: 5,
            connectionTimeout: 5,
            enableKeepAlive: true,
            keepAliveTimeout: 30,
            keepAliveMaxRequests: $maxRequests,
        );

        $server = new Server($config);
        $server->start();

        return $server;
    }

    /**
     * @return resource
     */
    private function createClient()
    {
        return $this->connectToPort($this->port);
    }

    /**
     * @return resource
     */
    private function connectToPort(int $port)
    {
        $client = stream_socket_client(
            "tcp://127.0.0.1:{$port}",
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
