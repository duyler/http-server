<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageTest extends TestCase
{
    #[Test]
    public function creates_text_message(): void
    {
        $message = new Message('Hello', Opcode::TEXT);

        $this->assertSame('Hello', $message->getData());
        $this->assertSame(Opcode::TEXT, $message->getOpcode());
        $this->assertTrue($message->isText());
        $this->assertFalse($message->isBinary());
        $this->assertSame(5, $message->getSize());
    }

    #[Test]
    public function creates_binary_message(): void
    {
        $binaryData = "\x00\x01\x02\x03";
        $message = new Message($binaryData, Opcode::BINARY);

        $this->assertSame($binaryData, $message->getData());
        $this->assertSame(Opcode::BINARY, $message->getOpcode());
        $this->assertFalse($message->isText());
        $this->assertTrue($message->isBinary());
        $this->assertSame(4, $message->getSize());
    }

    #[Test]
    public function parses_valid_json(): void
    {
        $jsonData = json_encode(['type' => 'hello', 'user' => 'Alice']);
        $message = new Message($jsonData, Opcode::TEXT);

        $parsed = $message->getJson();

        $this->assertIsArray($parsed);
        $this->assertSame('hello', $parsed['type']);
        $this->assertSame('Alice', $parsed['user']);
    }

    #[Test]
    public function returns_null_for_invalid_json(): void
    {
        $message = new Message('not valid json', Opcode::TEXT);

        $this->assertNull($message->getJson());
    }

    #[Test]
    public function returns_null_for_json_on_binary_message(): void
    {
        $jsonData = json_encode(['test' => 'value']);
        $message = new Message($jsonData, Opcode::BINARY);

        $this->assertNull($message->getJson());
    }

    #[Test]
    public function returns_null_for_non_array_json(): void
    {
        $message = new Message('"just a string"', Opcode::TEXT);

        $this->assertNull($message->getJson());
    }

    #[Test]
    public function handles_empty_message(): void
    {
        $message = new Message('', Opcode::TEXT);

        $this->assertSame('', $message->getData());
        $this->assertSame(0, $message->getSize());
    }

    #[Test]
    public function handles_large_message(): void
    {
        $largeData = str_repeat('A', 100000);
        $message = new Message($largeData, Opcode::TEXT);

        $this->assertSame($largeData, $message->getData());
        $this->assertSame(100000, $message->getSize());
    }

    #[Test]
    public function handles_unicode_text(): void
    {
        $unicodeText = '你好世界 🌍';
        $message = new Message($unicodeText, Opcode::TEXT);

        $this->assertSame($unicodeText, $message->getData());
        $this->assertTrue($message->isText());
    }

    #[Test]
    public function parses_nested_json(): void
    {
        $jsonData = json_encode([
            'type' => 'message',
            'data' => [
                'nested' => [
                    'deeply' => 'nested value',
                ],
            ],
        ]);
        $message = new Message($jsonData, Opcode::TEXT);

        $parsed = $message->getJson();

        $this->assertIsArray($parsed);
        $this->assertSame('nested value', $parsed['data']['nested']['deeply']);
    }

    #[Test]
    public function logs_debug_on_invalid_json(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'Failed to parse WebSocket message as JSON',
                $this->callback(fn(array $context): bool => isset($context['error'])
                    && isset($context['payload_length'])
                    && isset($context['opcode'])
                    && $context['opcode'] === 'TEXT'
                    && $context['payload_length'] === 14),
            );

        $message = new Message('not valid json', Opcode::TEXT, $logger);
        $message->getJson();
    }

    #[Test]
    public function logs_debug_on_non_array_json(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('debug')
            ->with(
                'WebSocket JSON message is not an array',
                $this->callback(fn(array $context): bool => isset($context['type']) && $context['type'] === 'string'),
            );

        $message = new Message('"just a string"', Opcode::TEXT, $logger);
        $message->getJson();
    }

    #[Test]
    public function does_not_log_on_valid_json(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('debug');

        $jsonData = json_encode(['type' => 'test']);
        $message = new Message($jsonData, Opcode::TEXT, $logger);
        $message->getJson();
    }

    #[Test]
    public function does_not_log_on_binary_message(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('debug');

        $message = new Message('not valid json', Opcode::BINARY, $logger);
        $message->getJson();
    }
}
