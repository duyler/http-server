<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Performance;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('performance')]
class ThroughputTest extends TestCase
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
            requestTimeout: 30,
            connectionTimeout: 30,
            enableKeepAlive: true,
            keepAliveMaxRequests: 2000,
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
    public function handles_1000_get_requests_with_acceptable_throughput(): void
    {
        $totalRequests = 1000;
        $client = $this->createClient();

        $startTime = microtime(true);

        for ($i = 0; $i < $totalRequests; $i++) {
            fwrite($client, "GET /bench/{$i} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            for ($wait = 0; $wait < 50; $wait++) {
                if ($this->server->hasRequest()) {
                    break;
                }
                usleep(100);
            }

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
            }

            fread($client, 8192);
        }

        $elapsed = microtime(true) - $startTime;
        fclose($client);

        $requestsPerSecond = $totalRequests / $elapsed;

        $this->assertGreaterThanOrEqual(
            1000,
            $requestsPerSecond,
            "Throughput should be at least 1000 req/sec, got " . round($requestsPerSecond, 2),
        );
    }

    #[Test]
    public function handles_sequential_requests_consistently(): void
    {
        $batchSize = 100;
        $client = $this->createClient();

        for ($i = 0; $i < $batchSize; $i++) {
            fwrite($client, "GET /consistent/{$i} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            for ($wait = 0; $wait < 50; $wait++) {
                if ($this->server->hasRequest()) {
                    break;
                }
                usleep(100);
            }

            $this->assertTrue($this->server->hasRequest(), "Request {$i} should be received");

            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame("/consistent/{$i}", $requestData->request->getUri()->getPath());
            $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));

            $raw = fread($client, 8192);
            $this->assertStringContainsString('200', $raw);
        }

        fclose($client);
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

        stream_set_timeout($client, 10);

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
