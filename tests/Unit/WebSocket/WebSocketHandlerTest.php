<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Processor\RequestProcessorInterface;
use Duyler\HttpServer\WebSocket\WebSocketConfig;
use Duyler\HttpServer\WebSocket\WebSocketHandler;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WebSocketHandlerTest extends TestCase
{
    private WebSocketHandler $handler;
    private ServerConfig $config;

    /** @var RequestProcessorInterface&MockObject */
    private RequestProcessorInterface $requestProcessor;

    protected function setUp(): void
    {
        $this->config = new ServerConfig();
        $this->requestProcessor = $this->createMock(RequestProcessorInterface::class);
        $this->handler = new WebSocketHandler($this->config, $this->requestProcessor);
    }

    #[Test]
    public function has_web_socket_servers_returns_false_initially(): void
    {
        $this->assertFalse($this->handler->hasWebSocketServers());
    }

    #[Test]
    public function has_web_socket_servers_returns_true_after_attach(): void
    {
        $wsServer = new WebSocketServer();
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $this->assertTrue($this->handler->hasWebSocketServers());
    }

    #[Test]
    public function get_web_socket_servers_returns_attached_servers(): void
    {
        $wsServer1 = new WebSocketServer();
        $wsServer2 = new WebSocketServer();

        $this->handler->attachWebSocketServer('/ws1', $wsServer1);
        $this->handler->attachWebSocketServer('/ws2', $wsServer2);

        $servers = $this->handler->getWebSocketServers();

        $this->assertArrayHasKey('/ws1', $servers);
        $this->assertArrayHasKey('/ws2', $servers);
    }

    #[Test]
    public function reset_clears_all_connections(): void
    {
        $wsServer = new WebSocketServer();
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $this->handler->reset();

        $this->assertTrue($this->handler->hasWebSocketServers());
    }

    #[Test]
    public function close_all_clears_connections(): void
    {
        $wsServer = new WebSocketServer();
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $this->handler->closeAll();

        $this->assertTrue($this->handler->hasWebSocketServers());
    }

    #[Test]
    public function logger_injected_via_constructor(): void
    {
        $logger = new NullLogger();
        $handler = new WebSocketHandler($this->config, $this->requestProcessor, logger: $logger);

        $this->assertInstanceOf(WebSocketHandler::class, $handler);
    }

    #[Test]
    public function process_keepalive_processes_all_servers(): void
    {
        $wsServer = new WebSocketServer(new WebSocketConfig());
        $this->handler->attachWebSocketServer('/ws', $wsServer);

        $this->handler->processKeepalive();

        $this->assertTrue($this->handler->hasWebSocketServers());
    }
}
