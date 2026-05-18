<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Message;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MessageTest extends TestCase
{
    public function testCreatesTextMessage(): void
    {
        $message = new Message('Hello', Opcode::TEXT);

        $this->assertSame('Hello', $message->getData());
        $this->assertSame(Opcode::TEXT, $message->getOpcode());
        $this->assertTrue($message->isText());
        $this->assertFalse($message->isBinary());
        $this->assertSame(5, $message->getSize());
    }

    public function testCreatesBinaryMessage(): void
    {
        $binaryData = "\x00\x01\x02\x03";
        $message = new Message($binaryData, Opcode::BINARY);

        $this->assertSame($binaryData, $message->getData());
        $this->assertSame(Opcode::BINARY, $message->getOpcode());
        $this->assertFalse($message->isText());
        $this->assertTrue($message->isBinary());
        $this->assertSame(4, $message->getSize());
    }

    public function testParsesValidJson(): void
    {
        $jsonData = json_encode(['type' => 'hello', 'user' => 'Alice']);
        $message = new Message($jsonData, Opcode::TEXT);

        $parsed = $message->getJson();

        $this->assertIsArray($parsed);
        $this->assertSame('hello', $parsed['type']);
        $this->assertSame('Alice', $parsed['user']);
    }

    public function testReturnsNullForInvalidJson(): void
    {
        $message = new Message('not valid json', Opcode::TEXT);

        $this->assertNull($message->getJson());
    }

    public function testReturnsNullForJsonOnBinaryMessage(): void
    {
        $jsonData = json_encode(['test' => 'value']);
        $message = new Message($jsonData, Opcode::BINARY);

        $this->assertNull($message->getJson());
    }

    public function testReturnsNullForNonArrayJson(): void
    {
        $message = new Message('"just a string"', Opcode::TEXT);

        $this->assertNull($message->getJson());
    }

    public function testHandlesEmptyMessage(): void
    {
        $message = new Message('', Opcode::TEXT);

        $this->assertSame('', $message->getData());
        $this->assertSame(0, $message->getSize());
    }

    public function testHandlesLargeMessage(): void
    {
        $largeData = str_repeat('A', 100000);
        $message = new Message($largeData, Opcode::TEXT);

        $this->assertSame($largeData, $message->getData());
        $this->assertSame(100000, $message->getSize());
    }

    public function testHandlesUnicodeText(): void
    {
        $unicodeText = '你好世界 🌍';
        $message = new Message($unicodeText, Opcode::TEXT);

        $this->assertSame($unicodeText, $message->getData());
        $this->assertTrue($message->isText());
    }

    public function testParsesNestedJson(): void
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

    public function testLogsDebugOnInvalidJson(): void
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

    public function testLogsDebugOnNonArrayJson(): void
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

    public function testDoesNotLogOnValidJson(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('debug');

        $jsonData = json_encode(['type' => 'test']);
        $message = new Message($jsonData, Opcode::TEXT, $logger);
        $message->getJson();
    }

    public function testDoesNotLogOnBinaryMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->never())
            ->method('debug');

        $message = new Message('not valid json', Opcode::BINARY, $logger);
        $message->getJson();
    }
}
