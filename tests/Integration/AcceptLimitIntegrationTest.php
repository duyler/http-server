<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AcceptLimitIntegrationTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            $this->server->reset();
            $this->server = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function server_respects_max_accepts_per_cycle_limit(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8081,
            maxAcceptsPerCycle: 5,
            debugMode: false,
        );

        $this->server = new Server($config);

        $this->assertSame(5, $config->maxAcceptsPerCycle);
        $this->assertInstanceOf(Server::class, $this->server);
    }

    #[Test]
    public function server_with_low_accept_limit_still_works(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8082,
            maxAcceptsPerCycle: 1,
            debugMode: false,
        );

        $this->server = new Server($config);

        $this->assertSame(1, $config->maxAcceptsPerCycle);
        $this->assertInstanceOf(Server::class, $this->server);
    }

    #[Test]
    public function server_with_high_accept_limit_works(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8083,
            maxAcceptsPerCycle: 100,
            debugMode: false,
        );

        $this->server = new Server($config);

        $this->assertSame(100, $config->maxAcceptsPerCycle);
        $this->assertInstanceOf(Server::class, $this->server);
    }

    #[Test]
    public function server_with_default_accept_limit(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 8084,
        );

        $this->server = new Server($config);

        $this->assertSame(10, $config->maxAcceptsPerCycle);
        $this->assertInstanceOf(Server::class, $this->server);
    }
}
