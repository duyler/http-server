<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\RateLimit;

use Duyler\HttpServer\RateLimit\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    #[Test]
    public function allows_requests_under_limit(): void
    {
        $limiter = new RateLimiter(5, 60);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->isAllowed('client1'));
        }
    }

    #[Test]
    public function blocks_requests_over_limit(): void
    {
        $limiter = new RateLimiter(3, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));
    }

    #[Test]
    public function tracks_different_identifiers_separately(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));
        $this->assertTrue($limiter->isAllowed('client2'));
    }

    #[Test]
    public function returns_remaining_requests(): void
    {
        $limiter = new RateLimiter(5, 60);

        $this->assertSame(5, $limiter->getRemainingRequests('client1'));

        $limiter->isAllowed('client1');
        $this->assertSame(4, $limiter->getRemainingRequests('client1'));

        $limiter->isAllowed('client1');
        $this->assertSame(3, $limiter->getRemainingRequests('client1'));
    }

    #[Test]
    public function returns_zero_remaining_when_limit_reached(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertSame(0, $limiter->getRemainingRequests('client1'));
    }

    #[Test]
    public function reset_clears_identifier(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));

        $limiter->reset('client1');

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    #[Test]
    public function cleanup_removes_old_requests(): void
    {
        $limiter = new RateLimiter(10, 1);

        $limiter->isAllowed('client1');
        $this->assertSame(1, $limiter->getActiveIdentifiersCount());

        sleep(2);

        $limiter->cleanup();
        $this->assertSame(0, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function sliding_window_allows_requests_after_time(): void
    {
        $limiter = new RateLimiter(2, 1);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));

        sleep(2);

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    #[Test]
    public function returns_reset_time(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');

        $resetTime = $limiter->getResetTime('client1');

        $this->assertGreaterThan(0, $resetTime);
        $this->assertLessThanOrEqual(60, $resetTime);
    }

    #[Test]
    public function returns_zero_reset_time_for_unknown_identifier(): void
    {
        $limiter = new RateLimiter(5, 60);

        $this->assertSame(0, $limiter->getResetTime('unknown'));
    }

    #[Test]
    public function get_config_returns_settings(): void
    {
        $limiter = new RateLimiter(100, 30);

        $config = $limiter->getConfig();

        $this->assertSame(100, $config['max_requests']);
        $this->assertSame(30, $config['window_seconds']);
    }

    #[Test]
    public function get_active_identifiers_count(): void
    {
        $limiter = new RateLimiter(5, 60);

        $this->assertSame(0, $limiter->getActiveIdentifiersCount());

        $limiter->isAllowed('client1');
        $this->assertSame(1, $limiter->getActiveIdentifiersCount());

        $limiter->isAllowed('client2');
        $this->assertSame(2, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function sliding_window_gradual_expiry(): void
    {
        $limiter = new RateLimiter(3, 2);

        $limiter->isAllowed('client1');
        usleep(500000);

        $limiter->isAllowed('client1');
        usleep(500000);

        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));

        sleep(2);

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    #[Test]
    public function handles_high_request_rate(): void
    {
        $limiter = new RateLimiter(100, 60);

        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($limiter->isAllowed('client1'));
        }

        $this->assertFalse($limiter->isAllowed('client1'));
    }

    #[Test]
    public function cleanup_preserves_active_requests(): void
    {
        $limiter = new RateLimiter(5, 10);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client2');

        $limiter->cleanup();

        $this->assertSame(2, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function auto_cleanup_triggers_after_interval(): void
    {
        $limiter = new RateLimiter(100, 1, 10);

        for ($i = 0; $i < 5; $i++) {
            $limiter->isAllowed('client' . $i);
        }

        $this->assertSame(5, $limiter->getActiveIdentifiersCount());

        sleep(2);

        for ($i = 5; $i < 15; $i++) {
            $limiter->isAllowed('client' . $i);
        }

        $this->assertSame(10, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function auto_cleanup_with_custom_interval(): void
    {
        $limiter = new RateLimiter(100, 1, 5);

        for ($i = 0; $i < 5; $i++) {
            $limiter->isAllowed('client' . $i);
        }

        $this->assertSame(5, $limiter->getActiveIdentifiersCount());

        sleep(2);

        for ($i = 5; $i < 10; $i++) {
            $limiter->isAllowed('client' . $i);
        }

        $this->assertSame(5, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function memory_usage_does_not_grow_infinitely(): void
    {
        $limiter = new RateLimiter(100, 1, 50);

        for ($i = 0; $i < 100; $i++) {
            $limiter->isAllowed('client' . $i);
        }

        $this->assertSame(100, $limiter->getActiveIdentifiersCount());

        sleep(2);

        for ($i = 100; $i < 150; $i++) {
            $limiter->isAllowed('client' . $i);
        }

        $this->assertSame(50, $limiter->getActiveIdentifiersCount());
    }

    #[Test]
    public function get_config_includes_cleanup_interval(): void
    {
        $limiter = new RateLimiter(100, 30, 50);

        $config = $limiter->getConfig();

        $this->assertSame(100, $config['max_requests']);
        $this->assertSame(30, $config['window_seconds']);
        $this->assertSame(50, $config['cleanup_interval']);
    }

    #[Test]
    public function default_cleanup_interval_is_100(): void
    {
        $limiter = new RateLimiter(100, 60);

        $config = $limiter->getConfig();

        $this->assertSame(100, $config['cleanup_interval']);
    }

}
