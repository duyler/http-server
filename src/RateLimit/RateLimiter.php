<?php

declare(strict_types=1);

namespace Duyler\HttpServer\RateLimit;

use Duyler\HttpServer\Security\AuditLoggerInterface;

final class RateLimiter
{
    /** @var array<string, array<int, float>> */
    private array $requests = [];

    private int $callCount = 0;

    public function __construct(
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 60,
        private readonly int $cleanupInterval = 100,
        private readonly int $maxIdentifiers = 10000,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    public function isAllowed(string $identifier): bool
    {
        $this->callCount++;

        if ($this->callCount % $this->cleanupInterval === 0) {
            $this->cleanup();
        }

        if (count($this->requests) >= $this->maxIdentifiers && !isset($this->requests[$identifier])) {
            $this->auditLogger?->logMaxIdentifiersReached(count($this->requests), $this->maxIdentifiers);
            return false;
        }

        $now = microtime(true);
        $windowStart = $now - (float) $this->windowSeconds;

        if (!isset($this->requests[$identifier])) {
            $this->requests[$identifier] = [$now];
            return true;
        }

        $this->removeExpired($identifier, $windowStart);

        if ($this->maxRequests > count($this->requests[$identifier])) {
            $this->requests[$identifier][] = $now;
            return true;
        }

        $this->auditLogger?->logRateLimitExceeded($identifier, count($this->requests[$identifier]));

        return false;
    }

    public function getRemainingRequests(string $identifier): int
    {
        $now = microtime(true);
        $windowStart = $now - (float) $this->windowSeconds;

        if (!isset($this->requests[$identifier])) {
            return $this->maxRequests;
        }

        $this->removeExpired($identifier, $windowStart);

        return max(0, $this->maxRequests - count($this->requests[$identifier]));
    }

    public function getResetTime(string $identifier): int
    {
        if (!isset($this->requests[$identifier]) || 0 === count($this->requests[$identifier])) {
            return 0;
        }

        $oldestRequest = min($this->requests[$identifier]);
        return (int) ceil($oldestRequest + (float) $this->windowSeconds - microtime(true));
    }

    public function reset(string $identifier): void
    {
        unset($this->requests[$identifier]);
    }

    public function cleanup(): void
    {
        $now = microtime(true);
        $windowStart = $now - (float) $this->windowSeconds;

        foreach ($this->requests as $identifier => $timestamps) {
            $this->removeExpired($identifier, $windowStart);

            if (0 === count($this->requests[$identifier])) {
                unset($this->requests[$identifier]);
            }
        }
    }

    private function removeExpired(string $identifier, float $windowStart): void
    {
        if (false === isset($this->requests[$identifier])) {
            return;
        }
        while ([] !== $this->requests[$identifier] && $this->requests[$identifier][0] <= $windowStart) {
            array_shift($this->requests[$identifier]);
        }
    }

    /**
     * @return array{max_requests: int, window_seconds: int, cleanup_interval: int, max_identifiers: int}
     */
    public function getConfig(): array
    {
        return [
            'max_requests' => $this->maxRequests,
            'window_seconds' => $this->windowSeconds,
            'cleanup_interval' => $this->cleanupInterval,
            'max_identifiers' => $this->maxIdentifiers,
        ];
    }

    public function getActiveIdentifiersCount(): int
    {
        return count($this->requests);
    }
}
