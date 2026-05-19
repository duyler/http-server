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
class MemoryUsageTest extends TestCase
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
            enableKeepAlive: false,
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
    public function memory_growth_per_10000_requests_under_1mb(): void
    {
        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);

        $totalRequests = 10000;
        $client = $this->createClient();

        for ($i = 0; $i < $totalRequests; $i++) {
            fwrite($client, "GET /mem/{$i} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            for ($wait = 0; $wait < 20; $wait++) {
                if ($this->server->hasRequest()) {
                    break;
                }
                usleep(50);
            }

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
            }

            fread($client, 4096);
        }

        fclose($client);

        gc_collect_cycles();
        $memoryAfter = memory_get_usage(true);
        $memoryGrowth = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            1 * 1024 * 1024,
            $memoryGrowth,
            "Memory growth for {$totalRequests} requests should be under 1MB, got " . round($memoryGrowth / 1024 / 1024, 2) . 'MB',
        );
    }

    #[Test]
    public function rate_limiter_memory_stays_bounded(): void
    {
        $limiter = new \Duyler\HttpServer\RateLimit\RateLimiter(
            maxRequests: 100,
            windowSeconds: 60,
            maxIdentifiers: 1000,
        );

        gc_collect_cycles();
        $memoryBefore = memory_get_usage();

        for ($i = 0; $i < 1000; $i++) {
            $limiter->isAllowed("10.0.0.{$i}");
        }

        $memoryAfter = memory_get_usage();
        $memoryGrowth = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            2 * 1024 * 1024,
            $memoryGrowth,
            'Rate limiter memory growth for 1000 identifiers should be under 2MB',
        );
    }

    #[Test]
    public function static_file_handler_cache_stays_bounded(): void
    {
        $publicDir = sys_get_temp_dir() . '/duyler_mem_test_' . uniqid();
        mkdir($publicDir, 0755, true);

        $maxCacheSize = 1024 * 1024;
        $handler = new \Duyler\HttpServer\Handler\StaticFileHandler(
            publicPath: $publicDir,
            enableCache: true,
            maxCacheSize: $maxCacheSize,
            maxCacheFiles: 100,
        );

        for ($i = 0; $i < 50; $i++) {
            file_put_contents($publicDir . "/file_{$i}.txt", str_repeat('x', 50000));
            $request = new \Nyholm\Psr7\ServerRequest('GET', "/file_{$i}.txt");
            $handler->handle($request);
        }

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual($maxCacheSize, $stats['size']);

        $handler->clearCache();

        for ($i = 0; $i < 50; $i++) {
            $file = $publicDir . "/file_{$i}.txt";
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($publicDir)) {
            rmdir($publicDir);
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
