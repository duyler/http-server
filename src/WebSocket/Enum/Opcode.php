<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket\Enum;

enum Opcode: int
{
    case CONTINUATION = 0x0;
    case TEXT = 0x1;
    case BINARY = 0x2;
    case CLOSE = 0x8;
    case PING = 0x9;
    case PONG = 0xA;

    public function isControl(): bool
    {
        return $this->value >= 0x8;
    }

    public function isData(): bool
    {
        return $this->value < 0x8;
    }
}
