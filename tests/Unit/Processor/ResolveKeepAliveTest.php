<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ResolveKeepAliveTest extends TestCase
{
    private ConnectionInterface&MockObject $connection;

    private ServerConfig $config;

    private HttpRequestProcessor $processor;

    #[Override]
    protected function setUp(): void
    {
        $this->config = new ServerConfig(enableKeepAlive: true, keepAliveMaxRequests: 100);
        $this->connection = $this->createMock(ConnectionInterface::class);

        $this->processor = (new ProcessorBuilder())->withConfig($this->config)->build();
    }

    #[Test]
    public function empty_connection_header_enables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(true);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function close_connection_header_disables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('close');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function keep_alive_connection_header_enables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('keep-alive');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(true);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function close_header_case_insensitive_disables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('Close');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function keep_alive_header_case_insensitive_enables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('Keep-Alive');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(true);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function max_requests_reached_disables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('');

        $this->connection->method('getRequestCount')->willReturn(100);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function max_requests_exceeded_disables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('keep-alive');

        $this->connection->method('getRequestCount')->willReturn(150);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function disabled_keep_alive_in_config_always_disables(): void
    {
        $config = new ServerConfig(enableKeepAlive: false, keepAliveMaxRequests: 100);
        $processor = (new ProcessorBuilder())->withConfig($config)->build();

        $request = $this->createRequestWithConnectionHeader('keep-alive');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function disabled_config_overrides_close_header(): void
    {
        $config = new ServerConfig(enableKeepAlive: false, keepAliveMaxRequests: 100);
        $processor = (new ProcessorBuilder())->withConfig($config)->build();

        $request = $this->createRequestWithConnectionHeader('close');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function boundary_request_count_at_max_minus_one_enables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('');

        $this->connection->method('getRequestCount')->willReturn(99);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(true);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function boundary_request_count_at_max_disables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('');

        $this->connection->method('getRequestCount')->willReturn(100);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(false);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function arbitrary_connection_header_enables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('upgrade');

        $this->connection->method('getRequestCount')->willReturn(1);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(true);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    #[Test]
    public function zero_request_count_enables_keep_alive(): void
    {
        $request = $this->createRequestWithConnectionHeader('');

        $this->connection->method('getRequestCount')->willReturn(0);
        $this->connection->expects($this->once())->method('setKeepAlive')->with(true);

        $this->processor->resolveKeepAlive($this->connection, $request);
    }

    private function createRequestWithConnectionHeader(string $connectionValue): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/test',
            headers: ['Connection' => $connectionValue],
        );
    }
}
