<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\ServerException;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class ServerWatchersTest extends TestCase
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
    public function startWatchersRequiresNotification(): void
    {
        $config = new ServerConfig(port: 18090);
        $this->server = new Server($config);
        $this->server->start();

        $this->expectException(ServerException::class);
        $this->server->startWatchers();
    }

    #[Test]
    #[Group('ev')]
    public function startWatchersIsIdempotent(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18091);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $this->server->startWatchers();
        $this->server->startWatchers();

        $this->assertTrue($this->server->hasWatchers());

        $this->server->stopWatchers();
    }

    #[Test]
    #[Group('ev')]
    public function stopWatchersClearsFlag(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18092);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $this->server->stopWatchers();

        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function getNotificationReadStreamReturnsNullWithoutNotification(): void
    {
        $config = new ServerConfig(port: 18093);
        $this->server = new Server($config);
        $this->server->start();

        $stream = $this->server->getNotificationReadStream();

        $this->assertNull($stream);
    }

    #[Test]
    public function getNotificationReadStreamReturnsResource(): void
    {
        $config = new ServerConfig(port: 18094);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $stream = $this->server->getNotificationReadStream();

        $this->assertIsResource($stream);

        $this->server->stopWatchers();
    }

    #[Test]
    public function hasWatchersReturnsFalseInitially(): void
    {
        $config = new ServerConfig(port: 18095);
        $this->server = new Server($config);
        $this->server->start();

        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function stopWatchersIsSafeWhenNotStarted(): void
    {
        $config = new ServerConfig(port: 18096);
        $this->server = new Server($config);
        $this->server->start();

        $this->server->stopWatchers();

        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function getNotificationReadStreamIsCached(): void
    {
        $config = new ServerConfig(port: 18097);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $stream1 = $this->server->getNotificationReadStream();
        $stream2 = $this->server->getNotificationReadStream();

        $this->assertSame($stream1, $stream2);

        $this->server->stopWatchers();
    }
}
