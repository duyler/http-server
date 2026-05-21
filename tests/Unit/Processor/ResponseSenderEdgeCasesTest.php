<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\ResponseSender;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ResponseSenderEdgeCasesTest extends TestCase
{
    private ResponseSender $sender;

    private ServerConfig $config;

    #[Override]
    protected function setUp(): void
    {
        $this->config = new ServerConfig();
        $this->sender = new ResponseSender($this->config, new ResponseWriter());
    }

    #[Test]
    public function send_handles_body_with_null_size(): void
    {
        $writtenData = '';
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $stream = Stream::create('');
        $stream->write('Dynamic content');

        $response = new Response(200, [], $stream);

        $this->sender->send($connection, $response);

        $this->assertStringContainsString('Content-Length: 15', $writtenData);
        $this->assertStringContainsString('Dynamic content', $writtenData);
    }

    #[Test]
    public function send_logs_warning_on_write_failure(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Failed to write response', $this->anything());

        $sender = new ResponseSender($this->config, new ResponseWriter(), $logger);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $connection->method('write')->willReturn(false);

        $response = new Response(200, [], 'Test');
        $sender->send($connection, $response);
    }

    #[Test]
    public function send_with_keep_alive_includes_keep_alive_header(): void
    {
        $writtenData = '';
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(true);
        $connection->method('getRequestCount')->willReturn(5);
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $response = new Response(200, [], 'OK');
        $this->sender->send($connection, $response);

        $this->assertStringContainsString('Connection: keep-alive', $writtenData);
        $this->assertStringContainsString('Keep-Alive:', $writtenData);
    }

    #[Test]
    public function send_with_existing_content_length_preserves_it(): void
    {
        $writtenData = '';
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $response = new Response(200, ['Content-Length' => '42'], 'Content');
        $this->sender->send($connection, $response);

        $this->assertStringContainsString('Content-Length: 42', $writtenData);
    }

    #[Test]
    public function send_error_with_custom_status(): void
    {
        $writtenData = '';
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $this->sender->sendError($connection, 503, 'Service Unavailable');

        $this->assertStringContainsString('503', $writtenData);
        $this->assertStringContainsString('Service Unavailable', $writtenData);
        $this->assertStringContainsString('Connection: close', $writtenData);
        $this->assertStringContainsString('Content-Type: text/plain', $writtenData);
    }
}
