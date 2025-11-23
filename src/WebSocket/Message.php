<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use JsonException;

class Message
{
    public function __construct(
        private readonly string $data,
        private readonly Opcode $opcode,
    ) {}

    public function getData(): string
    {
        return $this->data;
    }

    public function getOpcode(): Opcode
    {
        return $this->opcode;
    }

    public function isText(): bool
    {
        return $this->opcode === Opcode::TEXT;
    }

    public function isBinary(): bool
    {
        return $this->opcode === Opcode::BINARY;
    }

    /**
     * @return array<mixed>|null
     */
    public function getJson(): ?array
    {
        if (!$this->isText()) {
            return null;
        }

        try {
            $decoded = json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }

    public function getSize(): int
    {
        return strlen($this->data);
    }
}
