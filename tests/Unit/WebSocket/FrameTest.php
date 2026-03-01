<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketFrameException;
use Duyler\HttpServer\WebSocket\Frame;
use PHPUnit\Framework\TestCase;

class FrameTest extends TestCase
{
    public function testCreatesSimpleTextFrame(): void
    {
        $frame = new Frame(Opcode::TEXT, 'Hello', fin: true, masked: false);

        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame('Hello', $frame->payload);
        $this->assertTrue($frame->fin);
        $this->assertFalse($frame->masked);
        $this->assertNull($frame->maskingKey);
    }

    public function testCreatesMaskedFrame(): void
    {
        $maskingKey = "\x12\x34\x56\x78";
        $frame = new Frame(Opcode::TEXT, 'Hello', fin: true, masked: true, maskingKey: $maskingKey);

        $this->assertTrue($frame->masked);
        $this->assertSame($maskingKey, $frame->maskingKey);
    }

    public function testThrowsWhenMaskedWithoutKey(): void
    {
        $this->expectException(InvalidWebSocketFrameException::class);
        $this->expectExceptionMessage('Masked frame must have masking key');

        new Frame(Opcode::TEXT, 'Hello', masked: true);
    }

    public function testThrowsWhenMaskingKeyInvalidLength(): void
    {
        $this->expectException(InvalidWebSocketFrameException::class);
        $this->expectExceptionMessage('Masking key must be exactly 4 bytes');

        new Frame(Opcode::TEXT, 'Hello', masked: true, maskingKey: 'abc');
    }

    public function testEncodesSmallUnmaskedFrame(): void
    {
        $frame = new Frame(Opcode::TEXT, 'Hi', fin: true, masked: false);
        $encoded = $frame->encode();

        $this->assertSame("\x81\x02Hi", $encoded);
    }

    public function testEncodesMediumPayloadWithExtendedLength(): void
    {
        $payload = str_repeat('A', 200);
        $frame = new Frame(Opcode::TEXT, $payload, fin: true, masked: false);
        $encoded = $frame->encode();

        $this->assertSame(0x81, ord($encoded[0]));
        $this->assertSame(126, ord($encoded[1]));

        $length = unpack('n', substr($encoded, 2, 2))[1];
        $this->assertSame(200, $length);
    }

    public function testEncodesLargePayloadWith64bitLength(): void
    {
        $payload = str_repeat('B', 70000);
        $frame = new Frame(Opcode::BINARY, $payload, fin: true, masked: false);
        $encoded = $frame->encode();

        $this->assertSame(0x82, ord($encoded[0]));
        $this->assertSame(127, ord($encoded[1]));

        $length = unpack('J', substr($encoded, 2, 8))[1];
        $this->assertSame(70000, $length);
    }

    public function testEncodesMaskedFrame(): void
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

    public function testDecodesSimpleTextFrame(): void
    {
        $data = "\x81\x02Hi";
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame('Hi', $frame->payload);
        $this->assertTrue($frame->fin);
        $this->assertFalse($frame->masked);
    }

    public function testDecodesFragmentedFrame(): void
    {
        $data = "\x01\x05Hello";
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Opcode::TEXT, $frame->opcode);
        $this->assertSame('Hello', $frame->payload);
        $this->assertFalse($frame->fin);
    }

    public function testDecodesContinuationFrame(): void
    {
        $data = "\x80\x05World";
        $frame = Frame::decode($data);

        $this->assertInstanceOf(Frame::class, $frame);
        $this->assertSame(Opcode::CONTINUATION, $frame->opcode);
        $this->assertSame('World', $frame->payload);
        $this->assertTrue($frame->fin);
    }

    public function testDecodesMaskedFrame(): void
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

    public function testDecodesControlFrames(): void
    {
        $pingFrame = Frame::decode("\x89\x00");
        $this->assertSame(Opcode::PING, $pingFrame->opcode);

        $pongFrame = Frame::decode("\x8A\x00");
        $this->assertSame(Opcode::PONG, $pongFrame->opcode);

        $closeFrame = Frame::decode("\x88\x00");
        $this->assertSame(Opcode::CLOSE, $closeFrame->opcode);
    }

    public function testReturnsNullWhenNotEnoughData(): void
    {
        $this->assertNull(Frame::decode("\x81"));
        $this->assertNull(Frame::decode(""));
    }

    public function testReturnsNullWhenPayloadIncomplete(): void
    {
        $data = "\x81\x05Hi";
        $this->assertNull(Frame::decode($data));
    }

    public function testThrowsOnUnknownOpcode(): void
    {
        $this->expectException(InvalidWebSocketFrameException::class);
        $this->expectExceptionMessage('Unknown opcode: 15');

        Frame::decode("\x8F\x00");
    }

    public function testCalculatesFrameSizeCorrectly(): void
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

    public function testEncodeDecodeRoundtrip(): void
    {
        $original = new Frame(Opcode::TEXT, 'Hello WebSocket!', fin: true, masked: false);
        $encoded = $original->encode();
        $decoded = Frame::decode($encoded);

        $this->assertInstanceOf(Frame::class, $decoded);
        $this->assertSame($original->opcode, $decoded->opcode);
        $this->assertSame($original->payload, $decoded->payload);
        $this->assertSame($original->fin, $decoded->fin);
    }

    public function testEncodeDecodeRoundtripWithMasking(): void
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
