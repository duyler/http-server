<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;
use Throwable;

#[CoversClass(Server::class)]
class ServerNotificationFactoryTest extends TestCase
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
    public function enable_notification_creates_socket_pair(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();

        $socket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket);
    }

    #[Test]
    public function enable_notification_is_idempotent(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $socket1 = $this->server->getSocketResource();

        $this->server->enableNotification();
        $socket2 = $this->server->getSocketResource();

        $this->assertSame($socket1, $socket2);
    }

    #[Test]
    public function disable_notification_closes_both_sockets(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $socket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket);

        $this->server->disableNotification();

        $socketAfterDisable = $this->server->getSocketResource();
        $this->assertNull($socketAfterDisable);
    }

    #[Test]
    public function disable_notification_can_be_called_multiple_times(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $this->server->disableNotification();
        $this->server->disableNotification();

        $this->assertNull($this->server->getSocketResource());
    }

    #[Test]
    public function get_socket_resource_returns_notification_socket_after_enable(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->assertNull($this->server->getSocketResource());

        $this->server->enableNotification();
        $socket = $this->server->getSocketResource();

        $this->assertInstanceOf(Socket::class, $socket);
    }

    #[Test]
    public function get_socket_resource_returns_listening_socket_if_notification_disabled(): void
    {
        $config = new ServerConfig(port: 18094);
        $this->server = new Server($config);

        $this->server->start();
        $listeningSocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $listeningSocket);

        $this->server->enableNotification();
        $notificationSocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notificationSocket);
        $this->assertNotSame($listeningSocket, $notificationSocket);

        $this->server->disableNotification();
        $socketAfterDisable = $this->server->getSocketResource();
        $this->assertSame($listeningSocket, $socketAfterDisable);
    }

    #[Test]
    public function sockets_are_in_blocking_mode_after_enable(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $socket = $this->server->getSocketResource();

        $this->assertInstanceOf(Socket::class, $socket);

        $result = socket_set_nonblock($socket);
        $this->assertTrue($result);

        socket_set_block($socket);
    }

    #[Test]
    public function notification_works_after_reset(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $socket1 = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket1);

        $this->server->reset();

        $this->assertNull($this->server->getSocketResource());

        $this->server->enableNotification();
        $socket2 = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket2);
        $this->assertNotSame($socket1, $socket2);
    }

    #[Test]
    public function notification_works_after_stop(): void
    {
        $config = new ServerConfig(port: 18095);
        $this->server = new Server($config);

        $this->server->start();
        $this->server->enableNotification();
        $socket1 = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket1);

        $this->server->stop();

        $this->server->enableNotification();
        $socket2 = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket2);
        $this->assertNotSame($socket1, $socket2);
    }

    #[Test]
    public function full_cycle_enable_use_disable(): void
    {
        $config = new ServerConfig(port: 18096);
        $this->server = new Server($config);

        $this->server->start();
        $listeningSocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $listeningSocket);

        $this->server->enableNotification();

        $readSocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $readSocket);
        $this->assertNotSame($listeningSocket, $readSocket);

        $this->server->start();
        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_connect($client, '127.0.0.1', 18096);
        socket_write($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(10000);

        $read = [$readSocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThanOrEqual(0, $changed);

        socket_close($client);
        $this->server->disableNotification();

        $socketAfterDisable = $this->server->getSocketResource();
        $this->assertSame($listeningSocket, $socketAfterDisable);
    }

    #[Test]
    public function reset_disables_notification(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->enableNotification();
        $this->assertInstanceOf(Socket::class, $this->server->getSocketResource());

        $this->server->reset();

        $this->assertNull($this->server->getSocketResource());
    }

    #[Test]
    public function stop_disables_notification(): void
    {
        $config = new ServerConfig(port: 18097);
        $this->server = new Server($config);

        $this->server->start();
        $this->server->enableNotification();
        $this->assertInstanceOf(Socket::class, $this->server->getSocketResource());

        $this->server->stop();

        $this->assertNull($this->server->getSocketResource());
    }
}
