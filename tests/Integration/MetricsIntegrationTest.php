<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\TestCase;

class MetricsIntegrationTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            $this->server->reset();
            $this->server = null;
        }
        parent::tearDown();
    }

    public function testServerCollectsMetrics(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9001,
        );

        $this->server = new Server($config);
        $metrics = $this->server->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertArrayHasKey('successful_requests', $metrics);
        $this->assertArrayHasKey('failed_requests', $metrics);
        $this->assertArrayHasKey('active_connections', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('uptime_seconds', $metrics);
    }

    public function testMetricsIncludeCacheStats(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9002,
        );

        $this->server = new Server($config);
        $metrics = $this->server->getMetrics();

        $this->assertArrayHasKey('cache_hits', $metrics);
        $this->assertArrayHasKey('cache_misses', $metrics);
        $this->assertArrayHasKey('cache_hit_rate', $metrics);
    }

    public function testMetricsIncludeDurationStats(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9003,
        );

        $this->server = new Server($config);
        $metrics = $this->server->getMetrics();

        $this->assertArrayHasKey('avg_request_duration_ms', $metrics);
        $this->assertArrayHasKey('min_request_duration_ms', $metrics);
        $this->assertArrayHasKey('max_request_duration_ms', $metrics);
    }

    public function testMetricsIncludeConnectionStats(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9004,
        );

        $this->server = new Server($config);
        $metrics = $this->server->getMetrics();

        $this->assertArrayHasKey('closed_connections', $metrics);
        $this->assertArrayHasKey('timed_out_connections', $metrics);
    }

    public function testMetricsIncludeRequestsPerSecond(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9005,
        );

        $this->server = new Server($config);
        $metrics = $this->server->getMetrics();

        $this->assertArrayHasKey('requests_per_second', $metrics);
        $this->assertIsFloat($metrics['requests_per_second']);
    }

    public function testInitialMetricsHaveSensibleValues(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9006,
        );

        $this->server = new Server($config);
        $metrics = $this->server->getMetrics();

        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['successful_requests']);
        $this->assertSame(0, $metrics['failed_requests']);
        $this->assertSame(0, $metrics['active_connections']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertGreaterThanOrEqual(0, $metrics['uptime_seconds']);
    }
}
