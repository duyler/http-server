<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\WebSocket\WebSocketServer;
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
}
