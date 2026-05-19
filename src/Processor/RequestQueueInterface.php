<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\RequestData;

/**
 * Manages HTTP request queue with connection context tracking
 */
interface RequestQueueInterface
{
    /**
     * Enqueue a request with its connection context
     *
     * @param array{connection: ConnectionInterface, timestamp: float, cors_origin: ?string} $context
     */
    public function enqueue(RequestData $request, array $context): void;

    public function dequeue(): ?RequestData;

    public function hasRequest(): bool;

    public function remove(string $requestId): void;

    public function removeByConnection(ConnectionInterface $connection): void;

    /**
     * Remove stale requests older than timeout
     *
     * @param callable(ConnectionInterface, string): void $onStale Called for each stale connection with request ID
     */
    public function cleanupStale(int $timeout, callable $onStale): void;

    /**
     * Get connection context for a request
     *
     * @return array{connection: ConnectionInterface, timestamp: float, cors_origin: ?string}|null
     */
    public function getContext(string $requestId): ?array;

    /**
     * Check if there are pending responses (requests awaiting response)
     */
    public function hasPendingResponse(): bool;

    public function getPendingRequestId(): ?string;

    public function getPendingRequestCount(): int;

    public function getQueueCount(): int;

    public function reset(): void;
}
