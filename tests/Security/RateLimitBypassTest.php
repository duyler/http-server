<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Security;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\RateLimit\RateLimiter;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(RateLimiter::class)]
class RateLimitBypassTest extends TestCase
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
    public function excess_requests_get_429(): void
    {
        $maxRequests = 3;
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: true,
            rateLimitRequests: $maxRequests,
            rateLimitWindow: 60,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $responses = [];
        $totalRequests = $maxRequests + 5;

        for ($i = 0; $i < $totalRequests; $i++) {
            $client = $this->createClient();
            fwrite($client, "GET /path{$i} HTTP/1.1\r\nHost: localhost\r\n\r\n");
            usleep(100000);

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
            }

            usleep(50000);

            $raw = stream_get_contents($client);
            $responses[] = $raw;
            fclose($client);
        }

        $okCount = 0;
        $rateLimitedCount = 0;
        foreach ($responses as $response) {
            if (str_contains($response, '200')) {
                $okCount++;
            }
            if (str_contains($response, '429')) {
                $rateLimitedCount++;
            }
        }

        $this->assertGreaterThan(0, $okCount, 'Should have at least some successful requests');
        $this->assertGreaterThan(0, $rateLimitedCount, 'Should have rate-limited at least some requests');
        $this->assertLessThan($totalRequests, $okCount, 'Not all requests should succeed — rate limiting should kick in');
    }

    #[Test]
    public function x_forwarded_for_does_not_bypass_rate_limit(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: true,
            rateLimitRequests: 2,
            rateLimitWindow: 60,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $responses = [];
        for ($i = 0; $i < 4; $i++) {
            $client = $this->createClient();
            $spoofedIp = "10.0.0.{$i}";
            fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: {$spoofedIp}\r\n\r\n");
            usleep(100000);

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
            }

            usleep(50000);

            $raw = stream_get_contents($client);
            $responses[] = $raw;
            fclose($client);
        }

        $rateLimitedCount = 0;
        foreach ($responses as $response) {
            if (str_contains($response, '429')) {
                $rateLimitedCount++;
            }
        }

        $this->assertGreaterThanOrEqual(1, $rateLimitedCount, 'X-Forwarded-For spoofing should not bypass rate limit');
    }

    #[Test]
    public function different_paths_share_same_rate_counter(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            enableRateLimit: true,
            rateLimitRequests: 2,
            rateLimitWindow: 60,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $responses = [];
        $paths = ['/api/users', '/api/posts', '/api/comments', '/api/tags'];

        foreach ($paths as $path) {
            $client = $this->createClient();
            fwrite($client, "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");
            usleep(100000);

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
            }

            usleep(50000);

            $raw = stream_get_contents($client);
            $responses[] = $raw;
            fclose($client);
        }

        $rateLimitedCount = 0;
        foreach ($responses as $response) {
            if (str_contains($response, '429')) {
                $rateLimitedCount++;
            }
        }

        $this->assertGreaterThanOrEqual(1, $rateLimitedCount, 'Rate limit counter should be shared across paths');
    }

    #[Test]
    public function rate_limiter_unit_allows_under_limit(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 60);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->isAllowed('192.168.1.1'));
        }

        $this->assertFalse($limiter->isAllowed('192.168.1.1'));
    }

    #[Test]
    public function rate_limiter_unit_tracks_separate_ips(): void
    {
        $limiter = new RateLimiter(maxRequests: 2, windowSeconds: 60);

        $this->assertTrue($limiter->isAllowed('10.0.0.1'));
        $this->assertTrue($limiter->isAllowed('10.0.0.1'));
        $this->assertFalse($limiter->isAllowed('10.0.0.1'));

        $this->assertTrue($limiter->isAllowed('10.0.0.2'));
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
