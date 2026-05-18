<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Metrics;

use Duyler\HttpServer\Metrics\ServerMetrics;
use Override;
use PHPUnit\Framework\TestCase;

class ServerMetricsTest extends TestCase
{
    private ServerMetrics $metrics;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new ServerMetrics();
    }

    public function testInitialMetricsAreZero(): void
    {
        $metrics = $this->metrics->getMetrics();

        $this->assertSame(0, $metrics['total_requests']);
        $this->assertSame(0, $metrics['successful_requests']);
        $this->assertSame(0, $metrics['failed_requests']);
        $this->assertSame(0, $metrics['active_connections']);
        $this->assertSame(0, $metrics['total_connections']);
    }

    public function testIncrementRequestsIncreasesCounter(): void
    {
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();
        $this->metrics->incrementRequests();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(3, $metrics['total_requests']);
    }

    public function testIncrementSuccessfulRequests(): void
    {
        $this->metrics->incrementSuccessfulRequests();
        $this->metrics->incrementSuccessfulRequests();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(2, $metrics['successful_requests']);
    }

    public function testIncrementFailedRequests(): void
    {
        $this->metrics->incrementFailedRequests();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(1, $metrics['failed_requests']);
    }

    public function testSetActiveConnections(): void
    {
        $this->metrics->setActiveConnections(5);

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(5, $metrics['active_connections']);
    }

    public function testIncrementTotalConnections(): void
    {
        $this->metrics->incrementTotalConnections();
        $this->metrics->incrementTotalConnections();
        $this->metrics->incrementTotalConnections();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(3, $metrics['total_connections']);
    }

    public function testIncrementClosedConnections(): void
    {
        $this->metrics->incrementClosedConnections();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(1, $metrics['closed_connections']);
    }

    public function testIncrementTimedOutConnections(): void
    {
        $this->metrics->incrementTimedOutConnections();
        $this->metrics->incrementTimedOutConnections();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(2, $metrics['timed_out_connections']);
    }

    public function testIncrementCacheHits(): void
    {
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(3, $metrics['cache_hits']);
    }

    public function testIncrementCacheMisses(): void
    {
        $this->metrics->incrementCacheMisses();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(1, $metrics['cache_misses']);
    }

    public function testCacheHitRateCalculation(): void
    {
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheHits();
        $this->metrics->incrementCacheMisses();

        $metrics = $this->metrics->getMetrics();

        $this->assertSame(75.0, $metrics['cache_hit_rate']);
    }

    public function testCacheHitRateZeroWhenNoCacheAccess(): void
    {
        $metrics = $this->metrics->getMetrics();

        $this->assertSame(0.0, $metrics['cache_hit_rate']);
    }

    public function testRecordRequestDuration(): void
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

    public function testResetClearsAllMetrics(): void
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

    public function testUptimeIncreases(): void
    {
        $metrics1 = $this->metrics->getMetrics();
        sleep(1);
        $metrics2 = $this->metrics->getMetrics();

        $this->assertGreaterThanOrEqual(1, $metrics2['uptime_seconds']);
        $this->assertGreaterThan($metrics1['uptime_seconds'], $metrics2['uptime_seconds']);
    }

    public function testRequestsPerSecondCalculation(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->metrics->incrementRequests();
        }

        sleep(1);

        $metrics = $this->metrics->getMetrics();

        $this->assertGreaterThan(0, $metrics['requests_per_second']);
        $this->assertLessThanOrEqual(10, $metrics['requests_per_second']);
    }

    public function testRequestsPerSecondIsZeroInitially(): void
    {
        $metrics = $this->metrics->getMetrics();

        $this->assertIsFloat($metrics['requests_per_second']);
    }
}
