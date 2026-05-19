<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Notification;

use Duyler\HttpServer\Notification\NotificationManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Socket;

#[CoversClass(NotificationManager::class)]
class NotificationManagerTest extends TestCase
{
    private NotificationManager $manager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new NotificationManager(new NullLogger());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->manager->disable();
        parent::tearDown();
    }

    #[Test]
    public function enable_does_not_set_non_blocking(): void
    {
        $this->manager->enable();

        $readSocket = $this->manager->getReadSocket();
        $this->assertNotNull($readSocket);

        $result = socket_set_nonblock($readSocket);
        $this->assertTrue($result);

        socket_set_block($readSocket);
    }

    #[Test]
    public function get_read_socket_returns_valid_socket(): void
    {
        $this->manager->enable();

        $socket = $this->manager->getReadSocket();

        $this->assertInstanceOf(Socket::class, $socket);
    }

    #[Test]
    public function get_read_socket_returns_null_before_enable(): void
    {
        $this->assertNull($this->manager->getReadSocket());
    }

    #[Test]
    public function is_enabled_returns_false_before_enable(): void
    {
        $this->assertFalse($this->manager->isEnabled());
    }

    #[Test]
    public function is_enabled_returns_true_after_enable(): void
    {
        $this->manager->enable();
        $this->assertTrue($this->manager->isEnabled());
    }

    #[Test]
    public function is_enabled_returns_false_after_disable(): void
    {
        $this->manager->enable();
        $this->manager->disable();
        $this->assertFalse($this->manager->isEnabled());
    }

    #[Test]
    public function notify_writes_to_socket(): void
    {
        $this->manager->enable();

        $readSocket = $this->manager->getReadSocket();
        $this->assertNotNull($readSocket);

        $this->manager->notify();

        socket_set_nonblock($readSocket);
        $data = socket_read($readSocket, 1);
        $this->assertSame('x', $data);
    }

    #[Test]
    public function notify_does_nothing_before_enable(): void
    {
        $this->manager->notify();
        $this->assertFalse($this->manager->isEnabled());
    }

    #[Test]
    public function enable_is_idempotent(): void
    {
        $this->manager->enable();
        $socket1 = $this->manager->getReadSocket();

        $this->manager->enable();
        $socket2 = $this->manager->getReadSocket();

        $this->assertSame($socket1, $socket2);
    }

    #[Test]
    public function disable_closes_sockets(): void
    {
        $this->manager->enable();
        $this->manager->disable();

        $this->assertNull($this->manager->getReadSocket());
    }

    #[Test]
    public function reset_disables_notification(): void
    {
        $this->manager->enable();
        $this->manager->reset();

        $this->assertFalse($this->manager->isEnabled());
        $this->assertNull($this->manager->getReadSocket());
    }

    #[Test]
    public function set_notify_socket_does_not_exist(): void
    {
        $this->assertFalse(method_exists($this->manager, 'setNotifySocket'));
    }
}
