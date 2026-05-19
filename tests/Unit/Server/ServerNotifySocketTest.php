<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function set_event_loop_active_stores_flag(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(true);
        $this->assertTrue($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(false);
        $this->assertFalse($this->server->isEventLoopActive());
    }

    #[Test]
    public function is_event_loop_active_returns_false_by_default(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse($this->server->isEventLoopActive());
    }

    #[Test]
    public function enable_notification_creates_socket_pair(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();

        $stream = $this->server->getNotificationReadStream();
        $this->assertIsResource($stream);
    }

    #[Test]
    public function disable_notification_closes_sockets(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $stream = $this->server->getNotificationReadStream();
        $this->assertIsResource($stream);

        $this->server->disableNotification();

        $this->assertNull($this->server->getNotificationReadStream());
    }

    #[Test]
    public function get_notification_read_stream_returns_null_before_enable(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertNull($this->server->getNotificationReadStream());
    }

    #[Test]
    public function has_request_returns_false_in_reactive_mode_when_no_data(): void
    {
        $config = new ServerConfig(port: 18095);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $this->assertFalse($this->server->hasRequest());
    }

    #[Test]
    public function set_notify_socket_does_not_exist(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse(method_exists($this->server, 'setNotifySocket'));
    }

    #[Test]
    public function get_notify_socket_does_not_exist_in_public_api(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertFalse(method_exists($this->server, 'getNotifySocket'));
    }
}
