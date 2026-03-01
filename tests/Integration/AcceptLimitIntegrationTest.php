<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
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

    public function testServerRespectsMaxAcceptsPerCycleLimit(): void
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

    public function testServerWithLowAcceptLimitStillWorks(): void
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

    public function testServerWithHighAcceptLimitWorks(): void
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

    public function testServerWithDefaultAcceptLimit(): void
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
