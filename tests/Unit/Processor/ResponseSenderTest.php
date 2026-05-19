<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\ResponseSender;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseSenderTest extends TestCase
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
    public function send_adds_content_length_header(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);

        $writtenData = '';
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $response = new Response(200, [], 'Hello World');

        $this->sender->send($connection, $response);

        self::assertStringContainsString('Content-Length: 11', $writtenData);
    }

    #[Test]
    public function send_skips_invalid_connection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(false);

        $response = new Response(200, [], 'Hello');

        $connection->expects($this->never())->method('write');

        $this->sender->send($connection, $response);
    }

    #[Test]
    public function send_sets_keep_alive_headers(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(true);
        $connection->method('getRequestCount')->willReturn(5);

        $writtenData = '';
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $response = new Response(200, [], 'OK');
        $this->sender->send($connection, $response);

        self::assertStringContainsString('Connection: keep-alive', $writtenData);
        self::assertStringContainsString('Keep-Alive:', $writtenData);
    }

    #[Test]
    public function send_sets_close_header_when_not_keep_alive(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);

        $writtenData = '';
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $response = new Response(200, [], 'OK');
        $this->sender->send($connection, $response);

        self::assertStringContainsString('Connection: close', $writtenData);
    }

    #[Test]
    public function send_handles_write_failure_gracefully(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('getRemoteAddress')->willReturn('127.0.0.1');

        $writeCalled = false;
        $connection->method('write')->willReturnCallback(function () use (&$writeCalled): int|false {
            $writeCalled = true;
            return false;
        });

        $response = new Response(200, [], 'OK');

        $this->sender->send($connection, $response);

        self::assertTrue($writeCalled);
    }

    #[Test]
    public function send_error_creates_error_response(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $writtenData = '';
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $this->sender->sendError($connection, 404, 'Not Found');

        self::assertStringContainsString('404', $writtenData);
        self::assertStringContainsString('Not Found', $writtenData);
        self::assertStringContainsString('Connection: close', $writtenData);
        self::assertStringContainsString('Content-Type: text/plain', $writtenData);
    }

    #[Test]
    public function send_preserves_existing_content_length(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);

        $writtenData = '';
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $response = new Response(200, ['Content-Length' => '42'], 'Content');

        $this->sender->send($connection, $response);

        self::assertStringContainsString('Content-Length: 42', $writtenData);
    }

    #[Test]
    public function send_error_with_500_status(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $writtenData = '';
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int {
            $writtenData = $data;
            return strlen($data);
        });

        $this->sender->sendError($connection, 500, 'Internal Server Error');

        self::assertStringContainsString('500', $writtenData);
        self::assertStringContainsString('Internal Server Error', $writtenData);
    }
}
