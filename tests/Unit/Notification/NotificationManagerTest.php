<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Notification;

use Duyler\HttpServer\Notification\NotificationManager;
use Duyler\HttpServer\Socket\NotificationSocketPairInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(NotificationManager::class)]
class NotificationManagerTest extends TestCase
{
    private NotificationSocketPairInterface&MockObject $socketPair;

    private NotificationManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->socketPair = $this->createMock(NotificationSocketPairInterface::class);
        $this->manager = new NotificationManager($this->socketPair, new NullLogger());
    }

    #[Test]
    public function enable_creates_pair_when_not_enabled(): void
    {
        $this->socketPair->method('isEnabled')->willReturn(false);
        $this->socketPair->expects($this->once())->method('createPair');

        $this->manager->enable();
    }

    #[Test]
    public function enable_skips_when_already_enabled(): void
    {
        $this->socketPair->method('isEnabled')->willReturn(true);
        $this->socketPair->expects($this->never())->method('createPair');

        $this->manager->enable();
    }

    #[Test]
    public function disable_closes_socket_pair(): void
    {
        $this->socketPair->expects($this->once())->method('close');

        $this->manager->disable();
    }

    #[Test]
    public function is_enabled_delegates_to_socket_pair(): void
    {
        $this->socketPair->method('isEnabled')->willReturn(true);

        $this->assertTrue($this->manager->isEnabled());
    }

    #[Test]
    public function is_enabled_returns_false_when_pair_disabled(): void
    {
        $this->socketPair->method('isEnabled')->willReturn(false);

        $this->assertFalse($this->manager->isEnabled());
    }

    #[Test]
    public function get_read_socket_returns_socket_from_pair(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        $realSocket = $sockets[0];

        $this->socketPair->method('getReadSocket')->willReturn($realSocket);

        $result = $this->manager->getReadSocket();

        $this->assertSame($realSocket, $result);

        socket_close($sockets[0]);
        socket_close($sockets[1]);
    }

    #[Test]
    public function get_read_socket_returns_null_when_no_pair(): void
    {
        $this->socketPair->method('getReadSocket')->willReturn(null);

        $this->assertNull($this->manager->getReadSocket());
    }

    #[Test]
    public function get_notify_socket_returns_write_socket(): void
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        $realSocket = $sockets[1];

        $this->socketPair->method('getWriteSocket')->willReturn($realSocket);

        $result = $this->manager->getNotifySocket();

        $this->assertSame($realSocket, $result);

        socket_close($sockets[0]);
        socket_close($sockets[1]);
    }

    #[Test]
    public function get_notify_socket_returns_null_when_no_pair(): void
    {
        $this->socketPair->method('getWriteSocket')->willReturn(null);

        $this->assertNull($this->manager->getNotifySocket());
    }

    #[Test]
    public function notify_delegates_to_socket_pair(): void
    {
        $this->socketPair->expects($this->once())->method('notify');

        $this->manager->notify();
    }

    #[Test]
    public function reset_calls_disable(): void
    {
        $this->socketPair->expects($this->once())->method('close');

        $this->manager->reset();
    }

    #[Test]
    public function set_notify_socket_does_not_exist(): void
    {
        $this->assertFalse(method_exists($this->manager, 'setNotifySocket'));
    }
}
