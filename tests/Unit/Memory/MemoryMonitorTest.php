<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Memory;

use Duyler\HttpServer\MemoryMonitor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoryMonitor::class)]
final class MemoryMonitorTest extends TestCase
{
    #[Test]
    public function check_returns_true_when_under_limit(): void
    {
        $monitor = new MemoryMonitor(PHP_INT_MAX);

        $this->assertTrue($monitor->check());
    }

    #[Test]
    public function get_usage_returns_positive_value(): void
    {
        $monitor = new MemoryMonitor(134217728);

        $usage = $monitor->getUsage();

        $this->assertGreaterThan(0, $usage);
    }

    #[Test]
    public function get_peak_returns_at_least_current_usage(): void
    {
        $monitor = new MemoryMonitor(134217728);
        $monitor->check();

        $peak = $monitor->getPeak();
        $usage = $monitor->getUsage();

        $this->assertGreaterThanOrEqual($usage, $peak);
    }

    #[Test]
    public function get_usage_percent_returns_correct_value(): void
    {
        $monitor = new MemoryMonitor(PHP_INT_MAX);

        $percent = $monitor->getUsagePercent();

        $this->assertGreaterThanOrEqual(0.0, $percent);
    }

    #[Test]
    public function is_warning_threshold_returns_true_when_above_threshold(): void
    {
        $monitor = new MemoryMonitor(100);

        $this->assertTrue($monitor->isWarningThreshold(0));
    }

    #[Test]
    public function is_warning_threshold_returns_false_when_below_threshold(): void
    {
        $monitor = new MemoryMonitor(PHP_INT_MAX);

        $this->assertFalse($monitor->isWarningThreshold(80));
    }

    #[Test]
    public function check_updates_peak_memory(): void
    {
        $monitor = new MemoryMonitor(PHP_INT_MAX);

        $initialPeak = $monitor->getPeak();

        $monitor->check();

        $this->assertGreaterThanOrEqual($initialPeak, $monitor->getPeak());
    }

    #[Test]
    public function default_warning_threshold_is_80(): void
    {
        $monitor = new MemoryMonitor(PHP_INT_MAX);

        $this->assertFalse($monitor->isWarningThreshold());
    }

    #[Test]
    public function check_returns_false_when_memory_exceeds_limit(): void
    {
        $monitor = new MemoryMonitor(1);

        $this->assertFalse($monitor->check());
    }

    #[Test]
    public function get_usage_percent_returns_zero_when_limit_is_zero(): void
    {
        $monitor = new MemoryMonitor(0);

        $this->assertSame(0.0, $monitor->getUsagePercent());
    }
}
