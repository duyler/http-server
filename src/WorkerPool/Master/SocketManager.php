<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Socket;

class SocketManager
{
    private ?Socket $masterSocket = null;
    private bool $isListening = false;
    private bool $shouldCloseOnDestruct = true;

    public function __construct(
        private readonly ServerConfig $config,
    ) {}

    public function listen(): void
    {
        if ($this->isListening) {
            error_log("[SocketManager] Already listening, skipping");
            return;
        }

        error_log("[SocketManager] Creating socket...");
        $this->masterSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->masterSocket === false) {
            throw new WorkerPoolException('Failed to create master socket: ' . socket_strerror(socket_last_error()));
        }

        error_log("[SocketManager] Setting SO_REUSEADDR...");
        if (!socket_set_option($this->masterSocket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new WorkerPoolException('Failed to set SO_REUSEADDR: ' . socket_strerror(socket_last_error($this->masterSocket)));
        }

        error_log("[SocketManager] Binding to {$this->config->host}:{$this->config->port}...");
        if (!socket_bind($this->masterSocket, $this->config->host, $this->config->port)) {
            throw new WorkerPoolException(
                sprintf(
                    'Failed to bind to %s:%d: %s',
                    $this->config->host,
                    $this->config->port,
                    socket_strerror(socket_last_error($this->masterSocket)),
                ),
            );
        }

        $backlog = 128;
        error_log("[SocketManager] Starting to listen (backlog: $backlog)...");
        if (!socket_listen($this->masterSocket, $backlog)) {
            throw new WorkerPoolException('Failed to listen: ' . socket_strerror(socket_last_error($this->masterSocket)));
        }

        error_log("[SocketManager] Setting non-blocking mode...");
        socket_set_nonblock($this->masterSocket);

        $this->isListening = true;
        error_log("[SocketManager] ✅ Successfully listening on {$this->config->host}:{$this->config->port}");
    }

    public function accept(): ?Socket
    {
        static $acceptCalls = 0;
        $acceptCalls++;
        
        if (!$this->isListening) {
            if ($acceptCalls % 1000 === 0) {
                error_log("[SocketManager] WARNING: accept() called but not listening! (call #$acceptCalls)");
            }
            return null;
        }
        
        if ($this->masterSocket === null) {
            error_log("[SocketManager] ERROR: masterSocket is null!");
            return null;
        }

        $clientSocket = socket_accept($this->masterSocket);

        if ($clientSocket === false || $clientSocket === null) {
            $errno = socket_last_error($this->masterSocket);
            // EAGAIN (11) or EWOULDBLOCK (11) is normal for non-blocking socket
            if ($errno !== 11 && $errno !== 0) {
                error_log("[SocketManager] accept() error (errno=$errno): " . socket_strerror($errno));
            }
            return null;
        }

        error_log("[SocketManager] ✅✅✅ Accepted new connection! Setting non-blocking...");
        socket_set_nonblock($clientSocket);
        error_log("[SocketManager] Connection ready to be processed");

        return $clientSocket;
    }

    public function getSocket(): ?Socket
    {
        return $this->masterSocket;
    }

    public function detachFromWorker(): void
    {
        error_log("[SocketManager] Detaching socket in worker process (PID: " . getmypid() . ")");
        
        // ВАЖНО: НЕ закрываем socket!
        // При fork() дочерний процесс получает копию file descriptor,
        // но он указывает на ТОТ ЖЕ системный ресурс.
        // Если worker закроет socket, он закроется для Master тоже!
        // Просто забываем о нем - установим null и отключим auto-close.
        
        $this->masterSocket = null;
        $this->isListening = false;
        $this->shouldCloseOnDestruct = false;
        
        error_log("[SocketManager] Socket detached (not closed, just forgotten)");
    }

    public function isListening(): bool
    {
        return $this->isListening;
    }

    public function close(): void
    {
        error_log("[SocketManager] Closing socket...");
        if ($this->masterSocket !== null) {
            socket_close($this->masterSocket);
            $this->masterSocket = null;
        }

        $this->isListening = false;
        error_log("[SocketManager] Socket closed");
    }

    public function disableAutoClose(): void
    {
        $this->shouldCloseOnDestruct = false;
    }

    public function __destruct()
    {
        if ($this->shouldCloseOnDestruct) {
            $this->close();
        } else {
            error_log("[SocketManager] Skipping close in destructor (disabled)");
        }
    }
}
