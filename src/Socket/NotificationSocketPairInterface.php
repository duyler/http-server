<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Socket;

/**
 * Encapsulates a notification socket pair for inter-process signaling.
 *
 * Provides a simple mechanism for notifying an event loop that a new HTTP request
 * has been accepted and parsed, enabling reactive (non-polling) behavior.
 */
interface NotificationSocketPairInterface
{
    /**
     * Create a new Unix domain socket pair.
     *
     * Both sockets are set to non-blocking mode after creation.
     * If a pair already exists, it will be closed before creating a new one.
     *
     * @throws \Duyler\HttpServer\Exception\SocketException if socket pair creation fails
     */
    public function createPair(): void;

    /**
     * Get the read socket from the pair.
     *
     * This socket should be monitored by the event loop (e.g., via EvIo watcher)
     * to detect when a notification is available.
     */
    public function getReadSocket(): ?Socket;

    /**
     * Get the write socket from the pair.
     *
     * Used internally to send notification signals.
     */
    public function getWriteSocket(): ?Socket;

    /**
     * Send a notification signal through the socket pair.
     *
     * Writes a single byte to the write socket. The event loop monitoring
     * the read socket will wake up as a result.
     */
    public function notify(): void;

    /**
     * Close both sockets in the pair and release resources.
     */
    public function close(): void;

    /**
     * Check if the notification socket pair is enabled and ready to use.
     */
    public function isEnabled(): bool;
}
