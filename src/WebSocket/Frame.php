<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketFrameException;

class Frame
{
    public function __construct(
        public readonly Opcode $opcode,
        public readonly string $payload,
        public readonly bool $fin = true,
        public readonly bool $masked = false,
        public readonly ?string $maskingKey = null,
    ) {
        if ($this->masked && $this->maskingKey === null) {
            throw new InvalidWebSocketFrameException('Masked frame must have masking key');
        }

        if ($this->masked && strlen($this->maskingKey ?? '') !== 4) {
            throw new InvalidWebSocketFrameException('Masking key must be exactly 4 bytes');
        }
    }

    public function encode(): string
    {
        $byte1 = ($this->fin ? 0x80 : 0x00) | $this->opcode->value;
        $byte2 = $this->masked ? 0x80 : 0x00;

        $payloadLength = strlen($this->payload);
        $frame = chr($byte1);

        if ($payloadLength < 126) {
            $frame .= chr($byte2 | $payloadLength);
        } elseif ($payloadLength < 65536) {
            $frame .= chr($byte2 | 126);
            $frame .= pack('n', $payloadLength);
        } else {
            $frame .= chr($byte2 | 127);
            $frame .= pack('J', $payloadLength);
        }

        if ($this->masked && $this->maskingKey !== null) {
            $frame .= $this->maskingKey;
            $frame .= $this->mask($this->payload, $this->maskingKey);
        } else {
            $frame .= $this->payload;
        }

        return $frame;
    }

    public static function decode(string $data): ?self
    {
        if (strlen($data) < 2) {
            return null;
        }

        $byte1 = ord($data[0]);
        $byte2 = ord($data[1]);

        $fin = (bool) ($byte1 & 0x80);

        $opcodeValue = $byte1 & 0x0F;
        $opcode = Opcode::tryFrom($opcodeValue);

        if ($opcode === null) {
            throw new InvalidWebSocketFrameException("Unknown opcode: {$opcodeValue}");
        }

        $masked = (bool) ($byte2 & 0x80);
        $payloadLength = $byte2 & 0x7F;

        $offset = 2;

        if ($payloadLength === 126) {
            if (strlen($data) < $offset + 2) {
                return null;
            }
            $unpacked = unpack('n', substr($data, $offset, 2));
            if ($unpacked === false) {
                throw new InvalidWebSocketFrameException('Failed to unpack 16-bit payload length');
            }
            $payloadLength = $unpacked[1];
            $offset += 2;
        } elseif ($payloadLength === 127) {
            if (strlen($data) < $offset + 8) {
                return null;
            }
            $unpacked = unpack('J', substr($data, $offset, 8));
            if ($unpacked === false) {
                throw new InvalidWebSocketFrameException('Failed to unpack 64-bit payload length');
            }
            $payloadLength = $unpacked[1];
            $offset += 8;
        }

        $maskingKey = null;
        if ($masked) {
            if (strlen($data) < $offset + 4) {
                return null;
            }
            $maskingKey = substr($data, $offset, 4);
            $offset += 4;
        }

        if (strlen($data) < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($data, $offset, $payloadLength);

        if ($masked && $maskingKey !== null) {
            $payload = self::unmask($payload, $maskingKey);
        }

        return new self($opcode, $payload, $fin, $masked, $maskingKey);
    }

    public function getSize(): int
    {
        $headerSize = 2;

        $payloadLength = strlen($this->payload);

        if ($payloadLength >= 126 && $payloadLength < 65536) {
            $headerSize += 2;
        } elseif ($payloadLength >= 65536) {
            $headerSize += 8;
        }

        if ($this->masked) {
            $headerSize += 4;
        }

        return $headerSize + $payloadLength;
    }

    private function mask(string $data, string $key): string
    {
        $result = '';
        $keyLen = 4;
        $dataLen = strlen($data);

        for ($i = 0; $i < $dataLen; $i++) {
            $result .= $data[$i] ^ $key[$i % $keyLen];
        }

        return $result;
    }

    private static function unmask(string $data, string $key): string
    {
        $result = '';
        $keyLen = 4;
        $dataLen = strlen($data);

        for ($i = 0; $i < $dataLen; $i++) {
            $result .= $data[$i] ^ $key[$i % $keyLen];
        }

        return $result;
    }
}
