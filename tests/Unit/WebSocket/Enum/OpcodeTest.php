<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WebSocket\Enum;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OpcodeTest extends TestCase
{
    #[Test]
    public function has_correct_values(): void
    {
        $this->assertSame(0x0, Opcode::CONTINUATION->value);
        $this->assertSame(0x1, Opcode::TEXT->value);
        $this->assertSame(0x2, Opcode::BINARY->value);
        $this->assertSame(0x8, Opcode::CLOSE->value);
        $this->assertSame(0x9, Opcode::PING->value);
        $this->assertSame(0xA, Opcode::PONG->value);
    }

    #[Test]
    public function identifies_control_frames(): void
    {
        $this->assertTrue(Opcode::CLOSE->isControl());
        $this->assertTrue(Opcode::PING->isControl());
        $this->assertTrue(Opcode::PONG->isControl());
    }

    #[Test]
    public function identifies_data_frames(): void
    {
        $this->assertTrue(Opcode::CONTINUATION->isData());
        $this->assertTrue(Opcode::TEXT->isData());
        $this->assertTrue(Opcode::BINARY->isData());
    }

    #[Test]
    public function control_frames_are_not_data_frames(): void
    {
        $this->assertFalse(Opcode::CLOSE->isData());
        $this->assertFalse(Opcode::PING->isData());
        $this->assertFalse(Opcode::PONG->isData());
    }

    #[Test]
    public function data_frames_are_not_control_frames(): void
    {
        $this->assertFalse(Opcode::CONTINUATION->isControl());
        $this->assertFalse(Opcode::TEXT->isControl());
        $this->assertFalse(Opcode::BINARY->isControl());
    }
}
