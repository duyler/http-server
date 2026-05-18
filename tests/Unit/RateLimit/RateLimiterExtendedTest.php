<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\RateLimit;

use Duyler\HttpServer\RateLimit\RateLimiter;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RateLimiterExtendedTest extends TestCase
{
    public function testIsAllowedFiltersOldTimestamps(): void
    {
        $limiter = new RateLimiter(maxRequests: 2, windowSeconds: 1);

        $this->assertTrue($limiter->isAllowed('client1'));
        $this->assertTrue($limiter->isAllowed('client1'));

        usleep(1100000);

        $this->assertTrue($limiter->isAllowed('client1'));
    }

    public function testGetRemainingRequestsFiltersExpiredTimestamps(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');
        $limiter->isAllowed('client1');

        $this->assertSame(2, $limiter->getRemainingRequests('client1'));

        usleep(1100000);

        $this->assertSame(5, $limiter->getRemainingRequests('client1'));
    }

    public function testGetResetTimeReturnsZeroForEmptyRequestsArray(): void
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

    public function testResetForNonExistentIdentifierDoesNotThrow(): void
    {
        $limiter = new RateLimiter();

        $limiter->reset('nonexistent');

        $this->expectNotToPerformAssertions();
    }

    public function testCleanupRemovesIdentifiersWithNoActiveRequests(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 1);

        $limiter->isAllowed('client1');
        $limiter->isAllowed('client2');

        $this->assertSame(2, $limiter->getActiveIdentifiersCount());

        usleep(1100000);

        $limiter->cleanup();

        $this->assertSame(0, $limiter->getActiveIdentifiersCount());
    }

    public function testCleanupPreservesActiveRequests(): void
    {
        $limiter = new RateLimiter(maxRequests: 5, windowSeconds: 60);

        $limiter->isAllowed('active_client');

        $limiter->cleanup();

        $this->assertSame(1, $limiter->getActiveIdentifiersCount());
    }

    #[Override]
    protected function tearDown(): void {}
}
