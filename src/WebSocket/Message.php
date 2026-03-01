<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class Message
{
    public function __construct(
        private string $data,
        private Opcode $opcode,
        private LoggerInterface $logger = new NullLogger(),
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
        if (false === $this->isText()) {
            return null;
        }

        try {
            $decoded = json_decode($this->data, true, 512, JSON_THROW_ON_ERROR);

            if (false === is_array($decoded)) {
                $this->logger->debug('WebSocket JSON message is not an array', [
                    'type' => gettype($decoded),
                ]);
                return null;
            }

            return $decoded;
        } catch (JsonException $e) {
            $this->logger->debug('Failed to parse WebSocket message as JSON', [
                'error' => $e->getMessage(),
                'payload_length' => strlen($this->data),
                'opcode' => $this->opcode->name,
            ]);
            return null;
        }
    }

    public function getSize(): int
    {
        return strlen($this->data);
    }
}
