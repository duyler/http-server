<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\Tests\Support\ErrorReportingScope;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;
use Throwable;

#[CoversClass(Server::class)]
class NotificationEdgeCasesTest extends TestCase
{
    use ErrorReportingScope;
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
    public function multiple_requests_single_notification(): void
    {
        $config = new ServerConfig(port: 18100);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $this->server->setEventLoopActive(true);

        for ($i = 0; $i < 5; $i++) {
            $ch = curl_init('http://127.0.0.1:18100/test' . $i);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
            curl_exec($ch);
        }

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 0);

        $this->assertSame(0, $changed, 'No notification should be sent when Event Loop is active');

        $this->server->setEventLoopActive(false);

        $ch = curl_init('http://127.0.0.1:18100/trigger');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed, 'Notification should be sent when Event Loop is inactive');
    }

    #[Test]
    public function notification_after_event_loop_finishes(): void
    {
        $config = new ServerConfig(port: 18101);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $this->server->setEventLoopActive(true);

        $ch1 = curl_init('http://127.0.0.1:18101/first');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch1, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch1);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 0);
        $this->assertSame(0, $changed);

        $this->server->setEventLoopActive(false);

        usleep(10000);

        $ch2 = curl_init('http://127.0.0.1:18101/second');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch2);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed, 'Notification should be sent after Event Loop finishes');

        $data = $this->withSuppressedErrors(fn() => socket_read($notifySocket, 1));
        $this->assertSame('x', $data);
    }

    #[Test]
    public function concurrent_accept_and_notification(): void
    {
        $config = new ServerConfig(port: 18102);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $clients = [];
        for ($i = 0; $i < 3; $i++) {
            $clients[$i] = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            $this->assertNotFalse($clients[$i]);
            socket_connect($clients[$i], '127.0.0.1', 18102);
        }

        foreach ($clients as $client) {
            $request = "GET /concurrent HTTP/1.1\r\nHost: localhost\r\n\r\n";
            socket_write($client, $request);
        }

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed, 'Notification should be sent for concurrent connections');

        foreach ($clients as $client) {
            socket_close($client);
        }
    }

    #[Test]
    public function notification_during_graceful_shutdown(): void
    {
        $config = new ServerConfig(port: 18103);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $ch = curl_init('http://127.0.0.1:18103/during-shutdown');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed, 'Notification should work during shutdown');

        $data = $this->withSuppressedErrors(fn() => socket_read($notifySocket, 1));
        $this->assertSame('x', $data);

        $this->server->shutdown(1);
    }

    #[Test]
    public function rapid_enable_disable_notification(): void
    {
        $config = new ServerConfig(port: 18104);
        $this->server = new Server($config);
        $this->server->start();

        $listeningSocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $listeningSocket);

        for ($i = 0; $i < 5; $i++) {
            $this->server->enableNotification();
            $socket1 = $this->server->getSocketResource();
            $this->assertInstanceOf(Socket::class, $socket1);
            $this->assertNotSame($listeningSocket, $socket1, 'Notification socket should differ from listening socket');

            $this->server->disableNotification();
            $socketAfterDisable = $this->server->getSocketResource();
            $this->assertSame($listeningSocket, $socketAfterDisable, 'Should return listening socket after disable');
        }

        $this->server->enableNotification();
        $finalSocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $finalSocket);
        $this->assertNotSame($listeningSocket, $finalSocket);
    }

    #[Test]
    public function notification_with_reset_between_requests(): void
    {
        $config = new ServerConfig(port: 18105);
        $this->server = new Server($config);
        $this->server->start();

        $this->server->enableNotification();
        $socket1 = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket1);

        $ch = curl_init('http://127.0.0.1:18105/before-reset');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);

        $this->server->hasRequest();

        $read = [$socket1];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed);

        $this->server->reset();

        $this->server->start();
        $this->server->enableNotification();
        $socket2 = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $socket2);
        $this->assertNotSame($socket1, $socket2);

        $ch2 = curl_init('http://127.0.0.1:18105/after-reset');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch2);

        $this->server->hasRequest();

        $read = [$socket2];
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed);
    }

    #[Test]
    public function notification_buffer_overflow_protection(): void
    {
        $config = new ServerConfig(port: 18106);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init('http://127.0.0.1:18106/overflow' . $i);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
            curl_exec($ch);
            curl_close($ch);
        }

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed);

        $data = $this->withSuppressedErrors(fn() => socket_read($notifySocket, 4096));
        $this->assertGreaterThanOrEqual(1, strlen($data));
    }

    #[Test]
    public function notification_state_preserved_across_has_request_calls(): void
    {
        $config = new ServerConfig(port: 18107);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $this->server->setEventLoopActive(true);

        $ch = curl_init('http://127.0.0.1:18107/first');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);

        $this->server->hasRequest();

        $this->assertTrue($this->server->isEventLoopActive());

        $ch2 = curl_init('http://127.0.0.1:18107/second');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch2);

        $this->server->hasRequest();

        $this->assertTrue($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(false);
        $this->assertFalse($this->server->isEventLoopActive());
    }

    #[Test]
    public function notification_with_partial_request(): void
    {
        $config = new ServerConfig(port: 18108);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($client);
        socket_connect($client, '127.0.0.1', 18108);

        socket_write($client, "GET /partial HTTP/1.1\r\n");

        usleep(50000);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 0);

        $this->assertSame(0, $changed, 'No notification for incomplete request');

        socket_write($client, "Host: localhost\r\n\r\n");

        usleep(10000);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed, 'Notification sent after request completed');

        socket_close($client);
    }
}
