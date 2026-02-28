<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerConfigTest extends TestCase
{
    #[Test]
    public function default_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig();

        $this->assertSame(10, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function custom_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 25);

        $this->assertSame(25, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function rejects_zero_max_accepts_per_cycle(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max accepts per cycle must be positive');

        new ServerConfig(maxAcceptsPerCycle: 0);
    }

    #[Test]
    public function rejects_negative_max_accepts_per_cycle(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max accepts per cycle must be positive');

        new ServerConfig(maxAcceptsPerCycle: -1);
    }

    #[Test]
    public function accepts_one_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 1);

        $this->assertSame(1, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function accepts_large_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 1000);

        $this->assertSame(1000, $config->maxAcceptsPerCycle);
    }


    #[Test]
    public function default_socket_backlog(): void
    {
        $config = new ServerConfig();

        $this->assertSame(511, $config->socketBacklog);
    }

    #[Test]
    public function custom_socket_backlog(): void
    {
        $config = new ServerConfig(socketBacklog: 1024);

        $this->assertSame(1024, $config->socketBacklog);
    }

    #[Test]
    public function rejects_zero_socket_backlog(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Socket backlog must be positive');

        new ServerConfig(socketBacklog: 0);
    }

    #[Test]
    public function rejects_negative_socket_backlog(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Socket backlog must be positive');

        new ServerConfig(socketBacklog: -1);
    }

    #[Test]
    public function accepts_one_socket_backlog(): void
    {
        $config = new ServerConfig(socketBacklog: 1);

        $this->assertSame(1, $config->socketBacklog);
    }

    #[Test]
    public function default_header_cache_limit(): void
    {
        $config = new ServerConfig();

        $this->assertSame(100, $config->headerCacheLimit);
    }

    #[Test]
    public function custom_header_cache_limit(): void
    {
        $config = new ServerConfig(headerCacheLimit: 500);

        $this->assertSame(500, $config->headerCacheLimit);
    }

    #[Test]
    public function rejects_zero_header_cache_limit(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Header cache limit must be positive');

        new ServerConfig(headerCacheLimit: 0);
    }

    #[Test]
    public function rejects_negative_header_cache_limit(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Header cache limit must be positive');

        new ServerConfig(headerCacheLimit: -1);
    }

    #[Test]
    public function accepts_one_header_cache_limit(): void
    {
        $config = new ServerConfig(headerCacheLimit: 1);

        $this->assertSame(1, $config->headerCacheLimit);
    }
}
