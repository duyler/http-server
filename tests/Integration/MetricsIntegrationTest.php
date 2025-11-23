<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MetricsIntegrationTest extends TestCase
{
    #[Test]
    public function server_collects_metrics(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9001,
        );

        $server = new Server($config);
        $metrics = $server->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertArrayHasKey('successful_requests', $metrics);
        $this->assertArrayHasKey('failed_requests', $metrics);
        $this->assertArrayHasKey('active_connections', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('uptime_seconds', $metrics);
    }

    #[Test]
    public function metrics_include_cache_stats(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9002,
        );

        $server = new Server($config);
        $metrics = $server->getMetrics();

        $this->assertArrayHasKey('cache_hits', $metrics);
        $this->assertArrayHasKey('cache_misses', $metrics);
        $this->assertArrayHasKey('cache_hit_rate', $metrics);
    }

    #[Test]
    public function metrics_include_duration_stats(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9003,
        );

        $server = new Server($config);
        $metrics = $server->getMetrics();

        $this->assertArrayHasKey('avg_request_duration_ms', $metrics);
        $this->assertArrayHasKey('min_request_duration_ms', $metrics);
        $this->assertArrayHasKey('max_request_duration_ms', $metrics);
    }

    #[Test]
    public function metrics_include_connection_stats(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9004,
        );

        $server = new Server($config);
        $metrics = $server->getMetrics();

        $this->assertArrayHasKey('closed_connections', $metrics);
        $this->assertArrayHasKey('timed_out_connections', $metrics);
    }

    #[Test]
    public function metrics_include_requests_per_second(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9005,
        );

        $server = new Server($config);
        $metrics = $server->getMetrics();

        $this->assertArrayHasKey('requests_per_second', $metrics);
        $this->assertIsFloat($metrics['requests_per_second']);
    }

    #[Test]
    public function initial_metrics_have_sensible_values(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9006,
        );

        $server = new Server($config);
        $metrics = $server->getMetrics();

        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['successful_requests']);
        $this->assertSame(0, $metrics['failed_requests']);
        $this->assertSame(0, $metrics['active_connections']);
        $this->assertSame(0, $metrics['total_connections']);
        $this->assertGreaterThanOrEqual(0, $metrics['uptime_seconds']);
    }
}
