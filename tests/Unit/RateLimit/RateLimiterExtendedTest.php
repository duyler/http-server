<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\RateLimit;

use Duyler\HttpServer\RateLimit\RateLimiter;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RateLimiterExtendedTest extends TestCase
{
    #[Test]
    public function is_allowed_filters_old_timestamps(): void
    {
        $limiter = new RateLimiter(maxRequests: 2, windowSeconds: 1);

        $this->assertTrue($limiter->isAllowed('client1'));
        $this->assertTrue($limiter->isAllowed('client1'));

        usleep(1100000);

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    #[Test]
    public function get_remaining_requests_filters_expired_timestamps(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertSame(2, $limiter->getRemainingRequests('client1'));

        usleep(1100000);

        $this->assertSame(5, $limiter->getRemainingRequests('client1'));
    }

    #[Test]
    public function get_reset_time_returns_zero_for_empty_requests_array(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 60);

        $limiter->isAllowed('client1');

        $reflection = new ReflectionClass($limiter);
        $property = $reflection->getProperty('requests');
        $requests = $property->getValue($limiter);
        $requests['client1'] = [];
        $property->setValue($limiter, $requests);

        $this->assertSame(0, $limiter->getResetTime('client1'));
    }

    #[Test]
    public function reset_for_non_existent_identifier_does_not_throw(): void
    {
        $limiter = new RateLimiter();

        $limiter->reset('nonexistent');

        $this->assertSame(0, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function cleanup_removes_identifiers_with_no_active_requests(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client2');

        $this->assertSame(2, $limiter->getActiveIdentifiersCount());

        usleep(1100000);

        $limiter->cleanup();

        $this->assertSame(0, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function cleanup_preserves_active_requests(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 60);

        $limiter->isAllowed('active_client');

        $limiter->cleanup();

        $this->assertSame(1, $limiter->getActiveIdentifiersCount());
    }

    #[Override]
    protected function tearDown(): void {}
}
