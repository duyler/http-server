<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\RateLimit;

use Duyler\HttpServer\RateLimit\RateLimiter;
use Duyler\HttpServer\Security\AuditLoggerInterface;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    public function testAllowsRequestsUnderLimit(): void
    {
        $limiter = new RateLimiter(5, 60);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->isAllowed('client1'));
        }
    }

    public function testBlocksRequestsOverLimit(): void
    {
        $limiter = new RateLimiter(3, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));
    }

    public function testTracksDifferentIdentifiersSeparately(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));
        $this->assertTrue($limiter->isAllowed('client2'));
    }

    public function testReturnsRemainingRequests(): void
    {
        $limiter = new RateLimiter(5, 60);

        $this->assertSame(5, $limiter->getRemainingRequests('client1'));

        $limiter->isAllowed('client1');
        $this->assertSame(4, $limiter->getRemainingRequests('client1'));

        $limiter->isAllowed('client1');
        $this->assertSame(3, $limiter->getRemainingRequests('client1'));
    }

    public function testReturnsZeroRemainingWhenLimitReached(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertSame(0, $limiter->getRemainingRequests('client1'));
    }

    public function testResetClearsIdentifier(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));

        $limiter->reset('client1');

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    public function testCleanupRemovesOldRequests(): void
    {
        $limiter = new RateLimiter(10, 1);

        $limiter->isAllowed('client1');
        $this->assertSame(1, $limiter->getActiveIdentifiersCount());

        sleep(2);

        $limiter->cleanup();
        $this->assertSame(0, $limiter->getActiveIdentifiersCount());
    }

    public function testSlidingWindowAllowsRequestsAfterTime(): void
    {
        $limiter = new RateLimiter(2, 1);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertFalse($limiter->isAllowed('client1'));

        sleep(2);

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    public function testReturnsResetTime(): void
    {
        $limiter = new RateLimiter(2, 60);

        $limiter->isAllowed('client1');

        $resetTime = $limiter->getResetTime('client1');

        $this->assertGreaterThan(0, $resetTime);
        $this->assertLessThanOrEqual(60, $resetTime);
    }

    public function testReturnsZeroResetTimeForUnknownIdentifier(): void
    {
        $limiter = new RateLimiter(5, 60);

        $this->assertSame(0, $limiter->getResetTime('unknown'));
    }

    public function testGetConfigReturnsSettings(): void
    {
        $limiter = new RateLimiter(100, 30);

        $config = $limiter->getConfig();

        $this->assertSame(100, $config['max_requests']);
        $this->assertSame(30, $config['window_seconds']);
    }

    public function testGetActiveIdentifiersCount(): void
    {
        $limiter = new RateLimiter(5, 60);

        $this->assertSame(0, $limiter->getActiveIdentifiersCount());

        $limiter->isAllowed('client1');
        $this->assertSame(1, $limiter->getActiveIdentifiersCount());

        $limiter->isAllowed('client2');
        $this->assertSame(2, $limiter->getActiveIdentifiersCount());
    }

    public function testSlidingWindowGradualExpiry(): void
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

    public function testHandlesHighRequestRate(): void
    {
        $limiter = new RateLimiter(100, 60);

        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($limiter->isAllowed('client1'));
        }

        $this->assertFalse($limiter->isAllowed('client1'));
    }

    public function testCleanupPreservesActiveRequests(): void
    {
        $limiter = new RateLimiter(5, 10);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client2');

        $limiter->cleanup();

        $this->assertSame(2, $limiter->getActiveIdentifiersCount());
    }

    public function testAutoCleanupTriggersAfterInterval(): void
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

    public function testAutoCleanupWithCustomInterval(): void
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

    public function testMemoryUsageDoesNotGrowInfinitely(): void
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

    public function testGetConfigIncludesCleanupInterval(): void
    {
        $limiter = new RateLimiter(100, 30, 50);

        $config = $limiter->getConfig();

        $this->assertSame(100, $config['max_requests']);
        $this->assertSame(30, $config['window_seconds']);
        $this->assertSame(50, $config['cleanup_interval']);
    }

    public function testDefaultCleanupIntervalIs100(): void
    {
        $limiter = new RateLimiter(100, 60);

        $config = $limiter->getConfig();

        $this->assertSame(100, $config['cleanup_interval']);
    }

    public function testDefaultMaxIdentifiersIs10000(): void
    {
        $limiter = new RateLimiter(100, 60);

        $config = $limiter->getConfig();

        $this->assertSame(10000, $config['max_identifiers']);
    }

    public function testRejectsNewIdentifiersWhenLimitReached(): void
    {
        $limiter = new RateLimiter(maxIdentifiers: 2);

        $this->assertTrue($limiter->isAllowed('ip1'));
        $this->assertTrue($limiter->isAllowed('ip2'));
        $this->assertFalse($limiter->isAllowed('ip3'));
    }

    public function testAllowsExistingIdentifiersWhenLimitReached(): void
    {
        $limiter = new RateLimiter(maxIdentifiers: 2);

        $limiter->isAllowed('ip1');
        $limiter->isAllowed('ip2');

        $this->assertTrue($limiter->isAllowed('ip1'));
    }

    public function testGetConfigIncludesMaxIdentifiers(): void
    {
        $limiter = new RateLimiter(100, 60, 50, 5000);

        $config = $limiter->getConfig();

        $this->assertSame(5000, $config['max_identifiers']);
    }

    public function testLogsRateLimitExceededWhenRequestLimitReached(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->atLeastOnce())
            ->method('logRateLimitExceeded')
            ->with('client1', $this->greaterThanOrEqual(3));

        $limiter = new RateLimiter(3, 60, 100, 10000, $auditLogger);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');
        $this->assertFalse($limiter->isAllowed('client1'));
    }

    public function testLogsMaxIdentifiersReachedWhenIdentifierLimitReached(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->atLeastOnce())
            ->method('logMaxIdentifiersReached')
            ->with($this->greaterThanOrEqual(2), 2);

        $limiter = new RateLimiter(100, 60, 100, 2, $auditLogger);

        $limiter->isAllowed('ip1');
        $limiter->isAllowed('ip2');
        $this->assertFalse($limiter->isAllowed('ip3'));
    }
}
