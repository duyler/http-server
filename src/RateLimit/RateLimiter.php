<?php

declare(strict_types=1);

namespace Duyler\HttpServer\RateLimit;

class RateLimiter
{
    /** @var array<string, array<int, float>> */
    private array $requests = [];

    public function __construct(
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 60,
    ) {}

    public function isAllowed(string $identifier): bool
    {
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        if (!isset($this->requests[$identifier])) {
            $this->requests[$identifier] = [$now];
            return true;
        }

        $this->requests[$identifier] = array_filter(
            $this->requests[$identifier],
            fn(float $timestamp) => $timestamp > $windowStart,
        );

        if (count($this->requests[$identifier]) < $this->maxRequests) {
            $this->requests[$identifier][] = $now;
            return true;
        }

        return false;
    }

    public function getRemainingRequests(string $identifier): int
    {
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        if (!isset($this->requests[$identifier])) {
            return $this->maxRequests;
        }

        $activeRequests = array_filter(
            $this->requests[$identifier],
            fn(float $timestamp) => $timestamp > $windowStart,
        );

        return max(0, $this->maxRequests - count($activeRequests));
    }

    public function getResetTime(string $identifier): int
    {
        if (!isset($this->requests[$identifier]) || count($this->requests[$identifier]) === 0) {
            return 0;
        }

        $oldestRequest = min($this->requests[$identifier]);
        return (int) ceil($oldestRequest + $this->windowSeconds - microtime(true));
    }

    public function reset(string $identifier): void
    {
        unset($this->requests[$identifier]);
    }

    public function cleanup(): void
    {
        $now = microtime(true);
        $windowStart = $now - $this->windowSeconds;

        foreach ($this->requests as $identifier => $timestamps) {
            $this->requests[$identifier] = array_filter(
                $timestamps,
                fn(float $timestamp) => $timestamp > $windowStart,
            );

            if (count($this->requests[$identifier]) === 0) {
                unset($this->requests[$identifier]);
            }
        }
    }

    /**
     * @return array{max_requests: int, window_seconds: int}
     */
    public function getConfig(): array
    {
        return [
            'max_requests' => $this->maxRequests,
            'window_seconds' => $this->windowSeconds,
        ];
    }

    public function getActiveIdentifiersCount(): int
    {
        return count($this->requests);
    }
}
