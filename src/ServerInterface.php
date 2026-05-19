<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Fiber;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Socket;

interface ServerInterface
{
    public function start(): bool;

    public function stop(): void;

    public function reset(): void;

    public function restart(): bool;

    public function hasRequest(): bool;

    /**
     * Get next request with unique identifier
     *
     * Returns RequestData containing:
     * - Unique Request ID for response mapping
     * - PSR-7 ServerRequestInterface
     * - Connection identifier
     *
     * @return RequestData|null Request data or null if no requests available
     */
    public function getRequest(): ?RequestData;

    /**
     * Send response with request identifier
     *
     * ResponseData must contain requestId from corresponding RequestData
     * to ensure correct request-response mapping.
     *
     * @param ResponseData $responseData Response data with Request ID and response
     */
    public function respond(ResponseData $responseData): void;

    public function hasPendingResponse(): bool;

    /**
     * Get the request ID of a pending response
     *
     * Returns the first pending request ID if hasPendingResponse() is true.
     * Used by error handlers to send error responses for the current request.
     *
     * @return string|null Request ID or null if no pending response
     */
    public function getPendingRequestId(): ?string;

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
     * @param Socket|resource $clientSocket Client socket (Socket object or stream resource)
     * @param array{client_ip?: string, worker_id: int, worker_pid?: int} $metadata
     */
    public function addExternalConnection(mixed $clientSocket, array $metadata): void;

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

    /**
     * Unregister a previously registered Fiber
     *
     * Removes the Fiber from the internal registry. Returns true if the
     * Fiber was found and removed, false otherwise.
     */
    public function unregisterFiber(Fiber $fiber): bool;

    /**
     * Get socket resource for Event Loop integration (EvIo)
     *
     * Returns a resource suitable for use with EvIo watchers from the
     * PHP ev extension. The resource allows reactive event loop operation
     * without polling.
     *
     * Return values by server mode:
     * - Standalone: listening socket (Socket|resource)
     * - Worker Pool (SharedSocketMaster): shared listening socket (Socket)
     * - Worker Pool (CentralizedMaster): Unix socket pair for IPC (Socket)
     * - Server not started: null
     *
     * @return Socket|resource|null Socket resource or null if unavailable
     *
     * @see https://www.php.net/manual/en/class.evio.php EvIo documentation
     * @see Server::setExternalSocketResource() For manual resource assignment
     *
     * @example
     * ```php
     * $resource = $server->getSocketResource();
     * if (null !== $resource) {
     *     $watcher = new EvIo($resource, Ev::READ, $callback);
     * }
     * ```
     */
    public function getSocketResource(): mixed;

    /**
     * Set external socket resource for Worker Pool mode
     *
     * Called by Master classes (SharedSocketMaster, CentralizedMaster)
     * to provide the socket resource that will be monitored by EvIo
     * in the Event Bus.
     *
     * This method should be called after setWorkerId() and before
     * the application event loop starts.
     *
     * @param Socket|resource|null $resource Socket resource to use,
     *                                       or null to clear
     *
     * @see getSocketResource() To retrieve the resource
     * @see setWorkerId() To set Worker Pool mode
     *
     * @example
     * ```php
     * $server->setWorkerId(1);
     * $server->setExternalSocketResource($socket);
     * ```
     */
    public function setExternalSocketResource(mixed $resource): void;

    /**
     * Set Event Loop active flag
     *
     * Event Loop sets true before processing requests,
     * false after completion. Server uses this for optimization.
     */
    public function setEventLoopActive(bool $active): void;

    /**
     * Get Event Loop active flag
     */
    public function isEventLoopActive(): bool;

    /**
     * Enable notification mechanism for reactive Event Loop
     *
     * Creates socket pair for notifications. After calling this method,
     * getSocketResource() returns the notification socket.
     * Event Loop should monitor it via EvIo for wakeup when new requests arrive.
     *
     * @throws RuntimeException If failed to create socket pair
     */
    public function enableNotification(): void;

    /**
     * Disable notification mechanism
     *
     * Closes both ends of the socket pair and cleans up resources.
     */
    public function disableNotification(): void;

    /**
     * Create and start EvIo watchers for reactive mode.
     *
     * Must be called BEFORE Ev::run() in the same process.
     * Can be called multiple times (idempotent).
     *
     * For Standalone and SharedSocket modes only.
     * Centralized mode uses EvTimer fallback.
     *
     * @pre enableNotification() must be called first
     *
     * @throws \Duyler\HttpServer\Exception\ServerException If notification is not enabled
     */
    public function startWatchers(): void;

    /**
     * Stop and destroy all EvIo watchers.
     *
     * Call when Server stops or before re-creating watchers.
     */
    public function stopWatchers(): void;

    /**
     * Check if watchers are running.
     */
    public function hasWatchers(): bool;

    /**
     * Get notification socket read stream for EventBus.
     *
     * Returns stream resource for EvIo in EventBus.
     * Must be called after enableNotification().
     *
     * @return resource|null
     */
    public function getNotificationReadStream(): mixed;
}
