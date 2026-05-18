<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

final class MemoryMonitor
{
    private int $peakMemory = 0;

    public function __construct(
        private readonly int $memoryLimit,
    ) {}

    public function check(): bool
    {
        $currentMemory = memory_get_usage(true);
        $this->peakMemory = max($this->peakMemory, $currentMemory);

        return $currentMemory < $this->memoryLimit;
    }

    public function getUsage(): int
    {
        return memory_get_usage(true);
    }

    public function getPeak(): int
    {
        return $this->peakMemory;
    }

    public function getUsagePercent(): float
    {
        if (0 === $this->memoryLimit) {
            return 0.0;
        }

        return ((float) memory_get_usage(true) / (float) $this->memoryLimit) * 100.0;
    }

    public function isWarningThreshold(int $threshold = 80): bool
    {
        return $this->getUsagePercent() >= $threshold;
    }
}
