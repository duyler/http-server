<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Fiber;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Socket;

interface ServerInterface
{
    public function start(): bool;

    public function stop(): void;

    public function reset(): void;

    public function restart(): bool;

    public function hasRequest(): bool;

    public function getRequest(): ?ServerRequestInterface;

    public function respond(ResponseInterface $response): void;

    public function hasPendingResponse(): bool;

    public function shutdown(int $timeout): bool;

    public function setLogger(LoggerInterface $logger): void;

    public function attachWebSocket(string $path, WebSocketServer $ws): void;

    /**
     * @return array<string, int|float|string>
     */
    public function getMetrics(): array;

    /**
     * Add external connection from Worker Pool Master
     *
     * @param array{client_ip?: string, worker_id: int, worker_pid?: int} $metadata
     */
    public function addExternalConnection(Socket $clientSocket, array $metadata): void;

    public function getMode(): ServerMode;

    public function getWorkerId(): ?int;

    /**
     * Set worker ID for Worker Pool mode
     *
     * Called by Worker Pool Master when worker is started in Event-Driven mode.
     * Sets the server to Worker Pool mode automatically.
     *
     * @param int $workerId Worker ID (1, 2, 3, ...)
     */
    public function setWorkerId(int $workerId): void;

    /**
     * Register Fiber for automatic resume
     *
     * Used in Event-Driven mode to register background Fibers that accept
     * connections from Master. These Fibers will be automatically resumed
     * on each hasRequest() call.
     */
    public function registerFiber(Fiber $fiber): void;
}
