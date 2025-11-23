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
}
