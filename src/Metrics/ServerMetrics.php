<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Metrics;

use Duyler\HttpServer\Constants;

class ServerMetrics
{
    private int $totalRequests = 0;
    private int $successfulRequests = 0;
    private int $failedRequests = 0;
    private int $activeConnections = 0;
    private int $totalConnections = 0;
    private int $closedConnections = 0;
    private int $timedOutConnections = 0;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private float $totalRequestDuration = 0.0;
    private float $minRequestDuration = 0.0;
    private float $maxRequestDuration = 0.0;
    private int $startTime;

    public function __construct()
    {
        $this->startTime = time();
    }

    public function incrementRequests(): void
    {
        ++$this->totalRequests;
    }

    public function incrementSuccessfulRequests(): void
    {
        ++$this->successfulRequests;
    }

    public function incrementFailedRequests(): void
    {
        ++$this->failedRequests;
    }

    public function setActiveConnections(int $count): void
    {
        $this->activeConnections = $count;
    }

    public function incrementTotalConnections(): void
    {
        ++$this->totalConnections;
    }

    public function incrementClosedConnections(): void
    {
        ++$this->closedConnections;
    }

    public function incrementTimedOutConnections(): void
    {
        ++$this->timedOutConnections;
    }

    public function incrementCacheHits(): void
    {
        ++$this->cacheHits;
    }

    public function incrementCacheMisses(): void
    {
        ++$this->cacheMisses;
    }

    public function recordRequestDuration(float $duration): void
    {
        $this->totalRequestDuration += $duration;

        if ($this->minRequestDuration === 0.0 || $duration < $this->minRequestDuration) {
            $this->minRequestDuration = $duration;
        }

        if ($duration > $this->maxRequestDuration) {
            $this->maxRequestDuration = $duration;
        }
    }

    /**
     * @return array<string, int|float|string>
     */
    public function getMetrics(): array
    {
        $uptime = time() - $this->startTime;
        $avgDuration = $this->totalRequests > 0
            ? $this->totalRequestDuration / $this->totalRequests
            : 0.0;

        return [
            'uptime_seconds' => $uptime,
            'total_requests' => $this->totalRequests,
            'successful_requests' => $this->successfulRequests,
            'failed_requests' => $this->failedRequests,
            'active_connections' => $this->activeConnections,
            'total_connections' => $this->totalConnections,
            'closed_connections' => $this->closedConnections,
            'timed_out_connections' => $this->timedOutConnections,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'cache_hit_rate' => $this->getCacheHitRate(),
            'avg_request_duration_ms' => round($avgDuration * Constants::MILLISECONDS_PER_SECOND, 2),
            'min_request_duration_ms' => round($this->minRequestDuration * Constants::MILLISECONDS_PER_SECOND, 2),
            'max_request_duration_ms' => round($this->maxRequestDuration * Constants::MILLISECONDS_PER_SECOND, 2),
            'requests_per_second' => $uptime > 0 ? round($this->totalRequests / $uptime, 2) : 0.0,
        ];
    }

    public function reset(): void
    {
        $this->totalRequests = 0;
        $this->successfulRequests = 0;
        $this->failedRequests = 0;
        $this->activeConnections = 0;
        $this->totalConnections = 0;
        $this->closedConnections = 0;
        $this->timedOutConnections = 0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->totalRequestDuration = 0.0;
        $this->minRequestDuration = 0.0;
        $this->maxRequestDuration = 0.0;
        $this->startTime = time();
    }

    private function getCacheHitRate(): float
    {
        $total = $this->cacheHits + $this->cacheMisses;
        if ($total === 0) {
            return 0.0;
        }
        return round(($this->cacheHits / $total) * Constants::PERCENT_MULTIPLIER, 2);
    }
}
