<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WebSocketServerTest extends TestCase
{
    private WebSocketServer $server;

    #[Override]
    protected function setUp(): void
    {
        $this->server = new WebSocketServer(new WebSocketConfig());
    }

    public function testCreatesWithConfig(): void
    {
        $config = new WebSocketConfig(maxMessageSize: 2097152, maxFrameSize: 131072);
        $server = new WebSocketServer($config);

        $this->assertSame($config, $server->getConfig());
    }

    public function testSetsLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->server->setLogger($logger);

        $this->expectNotToPerformAssertions();
    }

    public function testRegistersEventListener(): void
    {
        $called = false;

        $this->server->on('test', function () use (&$called): void {
            $called = true;
        });

        $this->server->emit('test');

        $this->assertTrue($called);
    }

    public function testEmitsEventToMultipleListeners(): void
    {
        $callCount = 0;

        $this->server->on('test', function () use (&$callCount): void {
            $callCount++;
        });

        $this->server->on('test', function () use (&$callCount): void {
            $callCount++;
        });

        $this->server->emit('test');

        $this->assertSame(2, $callCount);
    }

    public function testPassesArgumentsToEventListeners(): void
    {
        $receivedArgs = [];

        $this->server->on('test', function (...$args) use (&$receivedArgs): void {
            $receivedArgs = $args;
        });

        $this->server->emit('test', 'arg1', 42, ['key' => 'value']);

        $this->assertSame(['arg1', 42, ['key' => 'value']], $receivedArgs);
    }

    public function testHandlesEventWithNoListeners(): void
    {
        $this->server->emit('nonexistent');

        $this->expectNotToPerformAssertions();
    }

    public function testLogsErrorsInEventHandlers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Error in WebSocket event handler',
                $this->callback(fn($context) => isset($context['event'])
                    && $context['event'] === 'test'
                    && isset($context['error'])),
            );

        $this->server->setLogger($logger);

        $this->server->on('test', function (): void {
            throw new RuntimeException('Test error');
        });

        $this->server->emit('test');
    }

    public function testReturnsZeroConnectionsInitially(): void
    {
        $this->assertSame(0, $this->server->getConnectionCount());
        $this->assertSame([], $this->server->getConnections());
    }

    public function testReturnsNullForNonexistentConnection(): void
    {
        $this->assertNull($this->server->getConnection('invalid_id'));
    }

    public function testReturnsEmptyArrayForNonexistentRoom(): void
    {
        $this->assertSame([], $this->server->getRoomConnections('nonexistent'));
        $this->assertSame(0, $this->server->getRoomCount('nonexistent'));
    }

    public function testCleanupReturnsZeroWhenNoClosedConnections(): void
    {
        $removed = $this->server->cleanupClosedConnections();

        $this->assertSame(0, $removed);
    }

    public function testCloseAllDoesNotFailWithNoConnections(): void
    {
        $this->server->closeAll();

        $this->expectNotToPerformAssertions();
    }

    public function testBroadcastDoesNotFailWithNoConnections(): void
    {
        $this->server->broadcast('test message');

        $this->expectNotToPerformAssertions();
    }

    public function testBroadcastToRoomDoesNotFailWithNonexistentRoom(): void
    {
        $this->server->broadcastToRoom('nonexistent', 'test message');

        $this->expectNotToPerformAssertions();
    }

    public function testProcessPingsDoesNotFailWithNoConnections(): void
    {
        $this->server->processPings();

        $this->expectNotToPerformAssertions();
    }

    public function testProcessPingsSkipsWhenAutoPingDisabled(): void
    {
        $config = new WebSocketConfig(autoPing: false);
        $server = new WebSocketServer($config);

        $server->processPings();

        $this->expectNotToPerformAssertions();
    }
}
