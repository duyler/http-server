<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;
use Throwable;

#[CoversClass(Server::class)]
class NotificationEvioTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stopWatchers();
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function notificationSocketIsReadableAfterRequest(): void
    {
        $config = new ServerConfig(port: 18091);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $ch = curl_init('http://127.0.0.1:18091/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);
        curl_close($ch);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed);

        $data = socket_read($notifySocket, 1);
        $this->assertSame('x', $data);
    }

    #[Test]
    public function eventLoopActivePreventsNotification(): void
    {
        $config = new ServerConfig(port: 18092);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $this->server->setEventLoopActive(true);

        $ch = curl_init('http://127.0.0.1:18092/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);
        curl_close($ch);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 0);

        $this->assertSame(0, $changed);
    }

    #[Test]
    public function multipleRequestsGenerateSingleNotificationWhenInactive(): void
    {
        $config = new ServerConfig(port: 18093);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        for ($i = 0; $i < 3; $i++) {
            $ch = curl_init('http://127.0.0.1:18093/test' . $i);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
            curl_exec($ch);
            curl_close($ch);
        }

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed);

        socket_set_nonblock($notifySocket);
        $data = '';
        while (false !== ($chunk = socket_read($notifySocket, 4096))) {
            $data .= $chunk;
        }

        $this->assertGreaterThanOrEqual(1, strlen($data));
    }

    #[Test]
    public function notificationAfterClearingEventLoopFlag(): void
    {
        $config = new ServerConfig(port: 18094);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $notifySocket = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $notifySocket);

        $this->server->setEventLoopActive(true);

        $ch = curl_init('http://127.0.0.1:18094/first');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);
        curl_close($ch);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 0);
        $this->assertSame(0, $changed);

        $this->server->setEventLoopActive(false);

        $ch2 = curl_init('http://127.0.0.1:18094/second');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch2);
        curl_close($ch2);

        $this->server->hasRequest();

        $read = [$notifySocket];
        $write = null;
        $except = null;
        $changed = socket_select($read, $write, $except, 1);
        $this->assertGreaterThan(0, $changed);

        $data = socket_read($notifySocket, 1);
        $this->assertSame('x', $data);
    }

    #[Test]
    public function getNotificationReadStreamWorks(): void
    {
        $config = new ServerConfig(port: 18095);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $stream = $this->server->getNotificationReadStream();

        $this->assertIsResource($stream);
    }

    #[Test]
    public function notificationStreamCanBeRead(): void
    {
        $config = new ServerConfig(port: 18096);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $stream = $this->server->getNotificationReadStream();
        $this->assertIsResource($stream);

        $ch = curl_init('http://127.0.0.1:18096/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_exec($ch);
        curl_close($ch);

        $this->server->hasRequest();

        $read = [$stream];
        $write = null;
        $except = null;
        $changed = stream_select($read, $write, $except, 1);

        $this->assertGreaterThan(0, $changed);

        $data = fread($stream, 1);
        $this->assertSame('x', $data);
    }
}
