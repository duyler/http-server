<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketFrameException;
use Duyler\HttpServer\WebSocket\Frame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FrameTest extends TestCase
{
    #[Test]
    public function creates_simple_text_frame(): void
    {
        $frame = new Frame(Opcode::TEXT, 'Hello', fin: true, masked: false);

        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame('Hello', $frame->payload);
        $this->assertTrue($frame->fin);
        $this->assertFalse($frame->masked);
        $this->assertNull($frame->maskingKey);
    }

    #[Test]
    public function creates_masked_frame(): void
    {
        $maskingKey = "\x12\x34\x56\x78";
        $frame = new Frame(Opcode::TEXT, 'Hello', fin: true, masked: true, maskingKey: $maskingKey);

        $this->assertTrue($frame->masked);
        $this->assertSame($maskingKey, $frame->maskingKey);
    }

    #[Test]
    public function throws_when_masked_without_key(): void
    {
        $this->expectException(InvalidWebSocketFrameException::class);
        $this->expectExceptionMessage('Masked frame must have masking key');

        new Frame(Opcode::TEXT, 'Hello', masked: true);
    }

    #[Test]
    public function throws_when_masking_key_invalid_length(): void
    {
        $this->expectException(InvalidWebSocketFrameException::class);
        $this->expectExceptionMessage('Masking key must be exactly 4 bytes');

        new Frame(Opcode::TEXT, 'Hello', masked: true, maskingKey: 'abc');
    }

    #[Test]
    public function encodes_small_unmasked_frame(): void
    {
        $frame = new Frame(Opcode::TEXT, 'Hi', fin: true, masked: false);
        $encoded = $frame->encode();

        $this->assertSame("\x81\x02Hi", $encoded);
    }

    #[Test]
    public function encodes_medium_payload_with_extended_length(): void
    {
        $payload = str_repeat('A', 200);
        $frame = new Frame(Opcode::TEXT, $payload, fin: true, masked: false);
        $encoded = $frame->encode();

        $this->assertSame(0x81, ord($encoded[0]));
        $this->assertSame(126, ord($encoded[1]));

        $length = unpack('n', substr($encoded, 2, 2))[1];
        $this->assertSame(200, $length);
    }

    #[Test]
    public function encodes_large_payload_with_64bit_length(): void
    {
        $payload = str_repeat('B', 70000);
        $frame = new Frame(Opcode::BINARY, $payload, fin: true, masked: false);
        $encoded = $frame->encode();

        $this->assertSame(0x82, ord($encoded[0]));
        $this->assertSame(127, ord($encoded[1]));

        $length = unpack('J', substr($encoded, 2, 8))[1];
        $this->assertSame(70000, $length);
    }

    #[Test]
    public function encodes_masked_frame(): void
    {
        $maskingKey = "\x12\x34\x56\x78";
        $frame = new Frame(Opcode::TEXT, 'Hi', fin: true, masked: true, maskingKey: $maskingKey);
        $encoded = $frame->encode();

        $this->assertSame(0x81, ord($encoded[0]));
        $this->assertSame(0x82, ord($encoded[1]));

        $extractedKey = substr($encoded, 2, 4);
        $this->assertSame($maskingKey, $extractedKey);

        $maskedPayload = substr($encoded, 6, 2);
        $this->assertNotSame('Hi', $maskedPayload);
    }

    #[Test]
    public function decodes_simple_text_frame(): void
    {
        $data = "\x81\x02Hi";
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame('Hi', $frame->payload);
        $this->assertTrue($frame->fin);
        $this->assertFalse($frame->masked);
    }

    #[Test]
    public function decodes_fragmented_frame(): void
    {
        $data = "\x01\x05Hello";
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame('Hello', $frame->payload);
        $this->assertFalse($frame->fin);
    }

    #[Test]
    public function decodes_continuation_frame(): void
    {
        $data = "\x80\x05World";
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Opcode::CONTINUATION, $frame->opcode);
        $this->assertSame('World', $frame->payload);
        $this->assertTrue($frame->fin);
    }

    #[Test]
    public function decodes_masked_frame(): void
    {
        $maskingKey = "\x12\x34\x56\x78";
        $payload = 'Test';
        $maskedPayload = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $maskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
        }

        $data = "\x81\x84" . $maskingKey . $maskedPayload;
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame('Test', $frame->payload);
        $this->assertTrue($frame->masked);
        $this->assertSame($maskingKey, $frame->maskingKey);
    }

    #[Test]
    public function decodes_control_frames(): void
    {
        $pingFrame = Frame::decode("\x89\x00");
        $this->assertSame(Opcode::PING, $pingFrame->opcode);

        $pongFrame = Frame::decode("\x8A\x00");
        $this->assertSame(Opcode::PONG, $pongFrame->opcode);

        $closeFrame = Frame::decode("\x88\x00");
        $this->assertSame(Opcode::CLOSE, $closeFrame->opcode);
    }

    #[Test]
    public function returns_null_when_not_enough_data(): void
    {
        $this->assertNull(Frame::decode("\x81"));
        $this->assertNull(Frame::decode(""));
    }

    #[Test]
    public function returns_null_when_payload_incomplete(): void
    {
        $data = "\x81\x05Hi";
        $this->assertNull(Frame::decode($data));
    }

    #[Test]
    public function throws_on_unknown_opcode(): void
    {
        $this->expectException(InvalidWebSocketFrameException::class);
        $this->expectExceptionMessage('Unknown opcode: 15');

        Frame::decode("\x8F\x00");
    }

    #[Test]
    public function calculates_frame_size_correctly(): void
    {
        $smallFrame = new Frame(Opcode::TEXT, 'Hi', fin: true, masked: false);
        $this->assertSame(4, $smallFrame->getSize());

        $mediumFrame = new Frame(Opcode::TEXT, str_repeat('A', 200), fin: true, masked: false);
        $this->assertSame(204, $mediumFrame->getSize());

        $largeFrame = new Frame(Opcode::TEXT, str_repeat('B', 70000), fin: true, masked: false);
        $this->assertSame(70010, $largeFrame->getSize());

        $maskedFrame = new Frame(Opcode::TEXT, 'Hi', fin: true, masked: true, maskingKey: "\x12\x34\x56\x78");
        $this->assertSame(8, $maskedFrame->getSize());
    }

    #[Test]
    public function encode_decode_roundtrip(): void
    {
        $original = new Frame(Opcode::TEXT, 'Hello WebSocket!', fin: true, masked: false);
        $encoded = $original->encode();
        $decoded = Frame::decode($encoded);

        $this->assertInstanceOf(Frame::class, $decoded);
        $this->assertSame($original->opcode, $decoded->opcode);
        $this->assertSame($original->payload, $decoded->payload);
        $this->assertSame($original->fin, $decoded->fin);
    }

    #[Test]
    public function encode_decode_roundtrip_with_masking(): void
    {
        $maskingKey = "\xAB\xCD\xEF\x01";
        $original = new Frame(Opcode::TEXT, 'Masked message', fin: true, masked: true, maskingKey: $maskingKey);
        $encoded = $original->encode();
        $decoded = Frame::decode($encoded);

        $this->assertInstanceOf(Frame::class, $decoded);
        $this->assertSame($original->payload, $decoded->payload);
        $this->assertTrue($decoded->masked);
    }
}
