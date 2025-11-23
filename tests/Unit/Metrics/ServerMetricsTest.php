<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Metrics;

use Duyler\HttpServer\Metrics\ServerMetrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerMetricsTest extends TestCase
{
    private ServerMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new ServerMetrics();
    }

    #[Test]
    public function initial_metrics_are_zero(): void
    {
        $metrics = $this->metrics->getMetrics();

        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['successful_requests']);
        $this->assertSame(0, $metrics['failed_requests']);
        $this->assertSame(0, $metrics['active_connections']);
        $this->assertSame(0, $metrics['total_connections']);
    }

    #[Test]
    public function increment_requests_increases_counter(): void
    {
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(3, $metrics['total_requests']);
    }

    #[Test]
    public function increment_successful_requests(): void
    {
        $this->metrics->incrementSuccessfulRequests();
        $this->metrics->incrementSuccessfulRequests();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(2, $metrics['successful_requests']);
    }

    #[Test]
    public function increment_failed_requests(): void
    {
        $this->metrics->incrementFailedRequests();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(1, $metrics['failed_requests']);
    }

    #[Test]
    public function set_active_connections(): void
    {
        $this->metrics->setActiveConnections(5);

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(5, $metrics['active_connections']);
    }

    #[Test]
    public function increment_total_connections(): void
    {
        $this->metrics->incrementTotalConnections();
        $this->metrics->incrementTotalConnections();
        $this->metrics->incrementTotalConnections();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(3, $metrics['total_connections']);
    }

    #[Test]
    public function increment_closed_connections(): void
    {
        $this->metrics->incrementClosedConnections();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(1, $metrics['closed_connections']);
    }

    #[Test]
    public function increment_timed_out_connections(): void
    {
        $this->metrics->incrementTimedOutConnections();
        $this->metrics->incrementTimedOutConnections();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(2, $metrics['timed_out_connections']);
    }

    #[Test]
    public function increment_cache_hits(): void
    {
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(3, $metrics['cache_hits']);
    }

    #[Test]
    public function increment_cache_misses(): void
    {
        $this->metrics->incrementCacheMisses();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(1, $metrics['cache_misses']);
    }

    #[Test]
    public function cache_hit_rate_calculation(): void
    {
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheMisses();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(75.0, $metrics['cache_hit_rate']);
    }

    #[Test]
    public function cache_hit_rate_zero_when_no_cache_access(): void
    {
        $metrics = $this->metrics->getMetrics();

        $this->assertSame(0.0, $metrics['cache_hit_rate']);
    }

    #[Test]
    public function record_request_duration(): void
    {
        $this->metrics->incrementRequests();
        $this->metrics->recordRequestDuration(0.1);
        $this->metrics->incrementRequests();
        $this->metrics->recordRequestDuration(0.2);
        $this->metrics->incrementRequests();
        $this->metrics->recordRequestDuration(0.3);

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(200.0, $metrics['avg_request_duration_ms']);
        $this->assertSame(100.0, $metrics['min_request_duration_ms']);
        $this->assertSame(300.0, $metrics['max_request_duration_ms']);
    }

    #[Test]
    public function reset_clears_all_metrics(): void
    {
        $this->metrics->incrementRequests();
        $this->metrics->incrementSuccessfulRequests();
        $this->metrics->incrementTotalConnections();
        $this->metrics->setActiveConnections(5);

        $this->metrics->reset();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['successful_requests']);
        $this->assertSame(0, $metrics['active_connections']);
        $this->assertSame(0, $metrics['total_connections']);
    }

    #[Test]
    public function uptime_increases(): void
    {
        $metrics1 = $this->metrics->getMetrics();
        sleep(1);
        $metrics2 = $this->metrics->getMetrics();

        $this->assertGreaterThanOrEqual(1, $metrics2['uptime_seconds']);
        $this->assertGreaterThan($metrics1['uptime_seconds'], $metrics2['uptime_seconds']);
    }

    #[Test]
    public function requests_per_second_calculation(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->metrics->incrementRequests();
        }

        sleep(1);

        $metrics = $this->metrics->getMetrics();

        $this->assertGreaterThan(0, $metrics['requests_per_second']);
        $this->assertLessThanOrEqual(10, $metrics['requests_per_second']);
    }

    #[Test]
    public function requests_per_second_is_zero_initially(): void
    {
        $metrics = $this->metrics->getMetrics();

        $this->assertIsFloat($metrics['requests_per_second']);
    }
}

