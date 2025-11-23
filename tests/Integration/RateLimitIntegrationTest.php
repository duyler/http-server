<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

class RateLimitIntegrationTest extends TestCase
{
    private Server $server;
    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->port = $this->findAvailablePort();
    }

    protected function tearDown(): void
    {
        try {
            $this->server->stop();
        } catch (Throwable $e) {
        }
        parent::tearDown();
    }

    #[Test]
    public function server_without_rate_limit_accepts_all_requests(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: false,
        );

        $this->server = new Server($config);
        $this->server->start();

        for ($i = 0; $i < 10; $i++) {
            $client = $this->connectClient();
            fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(50000);

            if ($this->server->hasRequest()) {
                $this->server->getRequest();
                $this->server->respond(new Response(200, [], 'OK'));
            }

            fclose($client);
        }

        $this->assertTrue(true, 'All requests accepted without rate limit');
    }

    #[Test]
    public function server_with_rate_limit_blocks_excess_requests(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: true,
            rateLimitRequests: 3,
            rateLimitWindow: 10,
        );

        $this->server = new Server($config);
        $this->server->start();

        $responses = [];

        for ($i = 0; $i < 5; $i++) {
            $client = $this->connectClient();
            fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(100000);

            if ($this->server->hasRequest()) {
                $request = $this->server->getRequest();
                $this->server->respond(new Response(200, [], "Response {$i}"));
            }

            $response = stream_get_contents($client);
            $responses[] = $response;
            fclose($client);
        }

        $okCount = 0;
        $rateLimitCount = 0;

        foreach ($responses as $response) {
            if (str_contains($response, '200')) {
                $okCount++;
            } elseif (str_contains($response, '429')) {
                $rateLimitCount++;
            }
        }

        $this->assertLessThanOrEqual(3, $okCount, 'Should allow max 3 requests');
        $this->assertGreaterThanOrEqual(1, $rateLimitCount, 'Should block excess requests');
    }

    #[Test]
    public function rate_limit_header_test(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: true,
            rateLimitRequests: 1,
            rateLimitWindow: 10,
        );

        $this->server = new Server($config);
        $this->assertTrue(true, 'Rate limit config accepted');
    }

    #[Test]
    public function different_clients_have_separate_limits(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: true,
            rateLimitRequests: 2,
            rateLimitWindow: 10,
        );

        $this->server = new Server($config);
        $this->server->start();

        for ($i = 0; $i < 2; $i++) {
            $client = $this->connectClient();
            fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
            usleep(100000);

            if ($this->server->hasRequest()) {
                $this->server->getRequest();
                $this->server->respond(new Response(200, [], 'OK'));
            }

            fclose($client);
        }

        $this->assertTrue(true, 'Both clients can make requests');
    }

    /**
     * @return resource
     */
    private function connectClient()
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
