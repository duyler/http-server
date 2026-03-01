<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class ServerNotifySocketTest extends TestCase
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
        }
        parent::tearDown();
    }

    public function testSetEventLoopActiveStoresFlag(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(true);
        $this->assertTrue($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(false);
        $this->assertFalse($this->server->isEventLoopActive());
    }

    public function testIsEventLoopActiveReturnsFalseByDefault(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse($this->server->isEventLoopActive());
    }

    public function testEnableNotificationCreatesSocketPair(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();

        $stream = $this->server->getNotificationReadStream();
        $this->assertIsResource($stream);
    }

    public function testDisableNotificationClosesSockets(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $stream = $this->server->getNotificationReadStream();
        $this->assertIsResource($stream);

        $this->server->disableNotification();

        $this->assertNull($this->server->getNotificationReadStream());
    }

    public function testGetNotificationReadStreamReturnsNullBeforeEnable(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertNull($this->server->getNotificationReadStream());
    }

    public function testHasRequestReturnsFalseInReactiveModeWhenNoData(): void
    {
        $config = new ServerConfig(port: 18095);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $this->assertFalse($this->server->hasRequest());
    }

    public function testSetNotifySocketDoesNotExist(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse(method_exists($this->server, 'setNotifySocket'));
    }

    public function testGetNotifySocketDoesNotExistInPublicApi(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse(method_exists($this->server, 'getNotifySocket'));
    }
}
