<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Exception\ServerException;
use Duyler\HttpServer\Server;
use Ev;
use EvIo;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class ReactiveModeTest extends TestCase
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
    #[Group('ev')]
    public function reactiveModeProcessesRequest(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18100);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $requestReceived = false;
        $notifyStream = $this->server->getNotificationReadStream();

        $this->assertIsResource($notifyStream);

        $notifyWatcher = new EvIo($notifyStream, Ev::READ, function () use (&$requestReceived): void {
            fread($this->server->getNotificationReadStream(), 4096);

            $this->server->setEventLoopActive(true);

            if ($this->server->hasRequest()) {
                $request = $this->server->getRequest();
                $requestReceived = null !== $request;

                if (null !== $request) {
                    $response = new Response(200, [], 'OK');
                    $this->server->respond(new ResponseData($request->id, $response));
                }
            }

            $this->server->setEventLoopActive(false);
        });
        $notifyWatcher->start();

        $ch = curl_init('http://127.0.0.1:18100/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
        curl_exec($ch);
        curl_close($ch);

        for ($i = 0; $i < 10; $i++) {
            Ev::run(Ev::RUN_NOWAIT);
            usleep(20000);
        }

        $this->assertTrue($requestReceived, 'Request should be received in reactive mode');

        $notifyWatcher->stop();
    }

    #[Test]
    #[Group('ev')]
    public function reactiveModeHandlesMultipleRequests(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig(port: 18101);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $processedCount = 0;
        $notifyStream = $this->server->getNotificationReadStream();

        $notifyWatcher = new EvIo($notifyStream, Ev::READ, function () use (&$processedCount): void {
            fread($this->server->getNotificationReadStream(), 4096);

            $this->server->setEventLoopActive(true);

            while ($this->server->hasRequest()) {
                $request = $this->server->getRequest();

                if (null !== $request) {
                    $processedCount++;
                    $response = new Response(200, [], 'Response ' . $processedCount);
                    $this->server->respond(new ResponseData($request->id, $response));
                }
            }

            $this->server->setEventLoopActive(false);
        });
        $notifyWatcher->start();

        for ($i = 0; $i < 5; $i++) {
            $ch = curl_init('http://127.0.0.1:18101/test' . $i);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 500);
            curl_exec($ch);
            curl_close($ch);
        }

        for ($i = 0; $i < 15; $i++) {
            Ev::run(Ev::RUN_NOWAIT);
            usleep(30000);
        }

        $this->assertSame(5, $processedCount, 'All 5 requests should be processed');

        $notifyWatcher->stop();
    }

    #[Test]
    public function watchersStartAndStop(): void
    {
        $config = new ServerConfig(port: 18102);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $this->assertFalse($this->server->hasWatchers());

        $this->server->startWatchers();
        $this->assertTrue($this->server->hasWatchers());

        $this->server->stopWatchers();
        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function startWatchersRequiresNotification(): void
    {
        $config = new ServerConfig(port: 18103);
        $this->server = new Server($config);
        $this->server->start();

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Notification must be enabled before starting watchers');

        $this->server->startWatchers();
    }

    #[Test]
    public function startWatchersIsIdempotent(): void
    {
        $config = new ServerConfig(port: 18104);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $this->server->startWatchers();
        $this->assertTrue($this->server->hasWatchers());

        $this->server->startWatchers();
        $this->assertTrue($this->server->hasWatchers());
    }

    #[Test]
    public function stopWatchersIsIdempotent(): void
    {
        $config = new ServerConfig(port: 18105);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $this->server->stopWatchers();
        $this->assertFalse($this->server->hasWatchers());

        $this->server->stopWatchers();
        $this->assertFalse($this->server->hasWatchers());
    }

    #[Test]
    public function getNotificationReadStreamReturnsNullWithoutNotification(): void
    {
        $config = new ServerConfig(port: 18106);
        $this->server = new Server($config);
        $this->server->start();

        $this->assertNull($this->server->getNotificationReadStream());
    }

    #[Test]
    public function getNotificationReadStreamReturnsResourceAfterEnable(): void
    {
        $config = new ServerConfig(port: 18107);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $stream = $this->server->getNotificationReadStream();

        $this->assertIsResource($stream);
    }

    #[Test]
    public function notificationStreamIsCached(): void
    {
        $config = new ServerConfig(port: 18108);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();

        $stream1 = $this->server->getNotificationReadStream();
        $stream2 = $this->server->getNotificationReadStream();

        $this->assertSame($stream1, $stream2);
    }

    #[Test]
    public function notificationIsClearedAfterStop(): void
    {
        $config = new ServerConfig(port: 18109);
        $this->server = new Server($config);
        $this->server->start();
        $this->server->enableNotification();
        $this->server->startWatchers();

        $this->assertTrue($this->server->hasWatchers());

        $this->server->stop();

        $this->assertFalse($this->server->hasWatchers());
        $this->assertNull($this->server->getNotificationReadStream());
    }
}
