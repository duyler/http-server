<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket\Enum;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use PHPUnit\Framework\TestCase;

class OpcodeTest extends TestCase
{
    public function testHasCorrectValues(): void
    {
        $this->assertSame(0x0, Opcode::CONTINUATION->value);
        $this->assertSame(0x1, Opcode::TEXT->value);
        $this->assertSame(0x2, Opcode::BINARY->value);
        $this->assertSame(0x8, Opcode::CLOSE->value);
        $this->assertSame(0x9, Opcode::PING->value);
        $this->assertSame(0xA, Opcode::PONG->value);
    }

    public function testIdentifiesControlFrames(): void
    {
        $this->assertTrue(Opcode::CLOSE->isControl());
        $this->assertTrue(Opcode::PING->isControl());
        $this->assertTrue(Opcode::PONG->isControl());
    }

    public function testIdentifiesDataFrames(): void
    {
        $this->assertTrue(Opcode::CONTINUATION->isData());
        $this->assertTrue(Opcode::TEXT->isData());
        $this->assertTrue(Opcode::BINARY->isData());
    }

    public function testControlFramesAreNotDataFrames(): void
    {
        $this->assertFalse(Opcode::CLOSE->isData());
        $this->assertFalse(Opcode::PING->isData());
        $this->assertFalse(Opcode::PONG->isData());
    }

    public function testDataFramesAreNotControlFrames(): void
    {
        $this->assertFalse(Opcode::CONTINUATION->isControl());
        $this->assertFalse(Opcode::TEXT->isControl());
        $this->assertFalse(Opcode::BINARY->isControl());
    }
}
