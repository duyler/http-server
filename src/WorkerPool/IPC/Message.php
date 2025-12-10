<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\IPC;

use InvalidArgumentException;
use JsonException;

readonly class Message
{
    public float $timestamp;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public MessageType $type,
        public array $data = [],
        ?float $timestamp = null,
    ) {
        $this->timestamp = $timestamp ?? microtime(true);
    }

    public function serialize(): string
    {
        return json_encode([
            'type' => $this->type->value,
            'data' => $this->data,
            'timestamp' => $this->timestamp,
        ], JSON_THROW_ON_ERROR);
    }

    public static function unserialize(string $data): self
    {
        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid message format: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Invalid message format');
        }

        if (!isset($decoded['type'])) {
            throw new InvalidArgumentException('Message type is required');
        }

        return new self(
            type: MessageType::from($decoded['type']),
            data: $decoded['data'] ?? [],
            timestamp: $decoded['timestamp'] ?? null,
        );
    }

    public static function connectionClosed(int $connectionId): self
    {
        return new self(
            type: MessageType::ConnectionClosed,
            data: ['connection_id' => $connectionId],
        );
    }

    public static function workerReady(int $workerId): self
    {
        return new self(
            type: MessageType::WorkerReady,
            data: ['worker_id' => $workerId],
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public static function workerMetrics(array $metrics): self
    {
        return new self(
            type: MessageType::WorkerMetrics,
            data: $metrics,
        );
    }

    public static function shutdown(): self
    {
        return new self(type: MessageType::Shutdown);
    }

    public static function reload(): self
    {
        return new self(type: MessageType::Reload);
    }
}
