<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Notification;

use Duyler\HttpServer\Notification\NotificationManager;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testEnableDoesNotSetNonBlocking(): void
    {
        $this->manager->enable();

        $readSocket = $this->manager->getReadSocket();
        $this->assertNotNull($readSocket);

        $result = socket_set_nonblock($readSocket);
        $this->assertTrue($result);

        socket_set_block($readSocket);
    }

    public function testGetReadSocketReturnsValidSocket(): void
    {
        $this->manager->enable();

        $socket = $this->manager->getReadSocket();

        $this->assertInstanceOf(Socket::class, $socket);
    }

    public function testGetReadSocketReturnsNullBeforeEnable(): void
    {
        $this->assertNull($this->manager->getReadSocket());
    }

    public function testIsEnabledReturnsFalseBeforeEnable(): void
    {
        $this->assertFalse($this->manager->isEnabled());
    }

    public function testIsEnabledReturnsTrueAfterEnable(): void
    {
        $this->manager->enable();
        $this->assertTrue($this->manager->isEnabled());
    }

    public function testIsEnabledReturnsFalseAfterDisable(): void
    {
        $this->manager->enable();
        $this->manager->disable();
        $this->assertFalse($this->manager->isEnabled());
    }

    public function testNotifyWritesToSocket(): void
    {
        $this->manager->enable();

        $readSocket = $this->manager->getReadSocket();
        $this->assertNotNull($readSocket);

        $this->manager->notify();

        socket_set_nonblock($readSocket);
        $data = socket_read($readSocket, 1);
        $this->assertSame('x', $data);
    }

    public function testNotifyDoesNothingBeforeEnable(): void
    {
        $this->manager->notify();
        $this->assertFalse($this->manager->isEnabled());
    }

    public function testEnableIsIdempotent(): void
    {
        $this->manager->enable();
        $socket1 = $this->manager->getReadSocket();

        $this->manager->enable();
        $socket2 = $this->manager->getReadSocket();

        $this->assertSame($socket1, $socket2);
    }

    public function testDisableClosesSockets(): void
    {
        $this->manager->enable();
        $this->manager->disable();

        $this->assertNull($this->manager->getReadSocket());
    }

    public function testResetDisablesNotification(): void
    {
        $this->manager->enable();
        $this->manager->reset();

        $this->assertFalse($this->manager->isEnabled());
        $this->assertNull($this->manager->getReadSocket());
    }

    public function testSetNotifySocketDoesNotExist(): void
    {
        $this->assertFalse(method_exists($this->manager, 'setNotifySocket'));
    }
}
