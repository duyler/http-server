<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

#[CoversClass(Server::class)]
class ServerClientWatchersTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function clientWatcherCreatedOnConnection(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18095);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $reflection = new ReflectionClass($this->server);
        $property = $reflection->getProperty('clientWatchers');

        $this->assertEmpty($property->getValue($this->server));

        $this->server->stopWatchers();
    }

    #[Test]
    public function stopWatchersClearsClientWatchers(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18096);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $this->server->stopWatchers();

        $reflection = new ReflectionClass($this->server);
        $property = $reflection->getProperty('clientWatchers');

        $this->assertEmpty($property->getValue($this->server));
    }

    #[Test]
    public function startWatchersCreatesListeningWatcher(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18097);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $reflection = new ReflectionClass($this->server);
        $property = $reflection->getProperty('listeningWatcher');

        $this->assertNotNull($property->getValue($this->server));

        $this->server->stopWatchers();
    }

    #[Test]
    public function stopWatchersClearsListeningWatcher(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18098);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();
        $this->server->stopWatchers();

        $reflection = new ReflectionClass($this->server);
        $property = $reflection->getProperty('listeningWatcher');

        $this->assertNull($property->getValue($this->server));
    }

    #[Test]
    public function closeConnectionRemovesClientWatcher(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18099);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $reflection = new ReflectionClass($this->server);
        $clientWatchersProperty = $reflection->getProperty('clientWatchers');

        $this->assertEmpty($clientWatchersProperty->getValue($this->server));

        $this->server->stopWatchers();
    }

    #[Test]
    public function watchersStartedFlagIsSet(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18100);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $this->assertFalse($this->server->hasWatchers());

        $this->server->startWatchers();

        $this->assertTrue($this->server->hasWatchers());

        $this->server->stopWatchers();

        $this->assertFalse($this->server->hasWatchers());
    }
}
