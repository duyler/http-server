<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\RequestData;
use Override;
use SplQueue;

final class RequestQueue implements RequestQueueInterface
{
    private readonly SplQueue $queue;

    /** @var array<string, array{connection: ConnectionInterface, timestamp: float, cors_origin: ?string}> */
    private array $contexts = [];

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    #[Override]
    public function enqueue(RequestData $request, array $context): void
    {
        $this->queue->enqueue($request);
        $this->contexts[$request->id] = $context;
    }

    #[Override]
    public function dequeue(): ?RequestData
    {
        while (false === $this->queue->isEmpty()) {
            $request = $this->queue->dequeue();
            assert($request instanceof RequestData);

            if (isset($this->contexts[$request->id])) {
                return $request;
            }
        }

        return null;
    }

    #[Override]
    public function hasRequest(): bool
    {
        return [] !== $this->contexts;
    }

    #[Override]
    public function remove(string $requestId): void
    {
        if (isset($this->contexts[$requestId])) {
            unset($this->contexts[$requestId]);
        }
    }

    #[Override]
    public function removeByConnection(ConnectionInterface $connection): void
    {
        foreach ($this->contexts as $requestId => $data) {
            if ($data['connection'] === $connection) {
                unset($this->contexts[$requestId]);
            }
        }
    }

    #[Override]
    public function cleanupStale(int $timeout, callable $onStale): void
    {
        $now = microtime(true);

        foreach ($this->contexts as $requestId => $data) {
            if (($now - $data['timestamp']) > $timeout) {
                $onStale($data['connection'], $requestId);
                unset($this->contexts[$requestId]);
            }
        }
    }

    #[Override]
    public function getContext(string $requestId): ?array
    {
        return $this->contexts[$requestId] ?? null;
    }

    #[Override]
    public function hasPendingResponse(): bool
    {
        return count($this->contexts) > 0;
    }

    #[Override]
    public function getPendingRequestId(): ?string
    {
        foreach ($this->contexts as $requestId => $data) {
            return $requestId;
        }

        return null;
    }

    #[Override]
    public function getPendingRequestCount(): int
    {
        return count($this->contexts);
    }

    #[Override]
    public function getQueueCount(): int
    {
        return $this->queue->count();
    }

    #[Override]
    public function reset(): void
    {
        while (false === $this->queue->isEmpty()) {
            $this->queue->dequeue();
        }
        $this->contexts = [];
    }
}
