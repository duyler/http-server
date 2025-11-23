<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WebSocketServerTest extends TestCase
{
    private WebSocketServer $server;

    protected function setUp(): void
    {
        $this->server = new WebSocketServer(new WebSocketConfig());
    }

    #[Test]
    public function creates_with_config(): void
    {
        $config = new WebSocketConfig(maxMessageSize: 2097152, maxFrameSize: 131072);
        $server = new WebSocketServer($config);

        $this->assertSame($config, $server->getConfig());
    }

    #[Test]
    public function sets_logger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->server->setLogger($logger);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function registers_event_listener(): void
    {
        $called = false;

        $this->server->on('test', function () use (&$called) {
            $called = true;
        });

        $this->server->emit('test');

        $this->assertTrue($called);
    }

    #[Test]
    public function emits_event_to_multiple_listeners(): void
    {
        $callCount = 0;

        $this->server->on('test', function () use (&$callCount) {
            $callCount++;
        });

        $this->server->on('test', function () use (&$callCount) {
            $callCount++;
        });

        $this->server->emit('test');

        $this->assertSame(2, $callCount);
    }

    #[Test]
    public function passes_arguments_to_event_listeners(): void
    {
        $receivedArgs = [];

        $this->server->on('test', function (...$args) use (&$receivedArgs) {
            $receivedArgs = $args;
        });

        $this->server->emit('test', 'arg1', 42, ['key' => 'value']);

        $this->assertSame(['arg1', 42, ['key' => 'value']], $receivedArgs);
    }

    #[Test]
    public function handles_event_with_no_listeners(): void
    {
        $this->server->emit('nonexistent');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function logs_errors_in_event_handlers(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Error in WebSocket event handler',
                $this->callback(function ($context) {
                    return isset($context['event'])
                        && $context['event'] === 'test'
                        && isset($context['error']);
                }),
            );

        $this->server->setLogger($logger);

        $this->server->on('test', function () {
            throw new RuntimeException('Test error');
        });

        $this->server->emit('test');
    }

    #[Test]
    public function returns_zero_connections_initially(): void
    {
        $this->assertSame(0, $this->server->getConnectionCount());
        $this->assertSame([], $this->server->getConnections());
    }

    #[Test]
    public function returns_null_for_nonexistent_connection(): void
    {
        $this->assertNull($this->server->getConnection('invalid_id'));
    }

    #[Test]
    public function returns_empty_array_for_nonexistent_room(): void
    {
        $this->assertSame([], $this->server->getRoomConnections('nonexistent'));
        $this->assertSame(0, $this->server->getRoomCount('nonexistent'));
    }

    #[Test]
    public function cleanup_returns_zero_when_no_closed_connections(): void
    {
        $removed = $this->server->cleanupClosedConnections();

        $this->assertSame(0, $removed);
    }

    #[Test]
    public function close_all_does_not_fail_with_no_connections(): void
    {
        $this->server->closeAll();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function broadcast_does_not_fail_with_no_connections(): void
    {
        $this->server->broadcast('test message');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function broadcast_to_room_does_not_fail_with_nonexistent_room(): void
    {
        $this->server->broadcastToRoom('nonexistent', 'test message');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function process_pings_does_not_fail_with_no_connections(): void
    {
        $this->server->processPings();

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function process_pings_skips_when_auto_ping_disabled(): void
    {
        $config = new WebSocketConfig(autoPing: false);
        $server = new WebSocketServer($config);

        $server->processPings();

        $this->expectNotToPerformAssertions();
    }
}
