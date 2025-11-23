<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\WebSocket\Enum\CloseCode;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class WebSocketServer
{
    /**
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * @var array<string, array<string, Connection>>
     */
    private array $rooms = [];

    /**
     * @var array<string, array<callable>>
     */
    private array $eventListeners = [];

    private LoggerInterface $logger;

    public function __construct(
        private readonly WebSocketConfig $config = new WebSocketConfig(),
    ) {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param callable $callback
     */
    public function on(string $event, callable $callback): void
    {
        if (!isset($this->eventListeners[$event])) {
            $this->eventListeners[$event] = [];
        }

        $this->eventListeners[$event][] = $callback;
    }

    /**
     * @param mixed ...$args
     */
    public function emit(string $event, mixed ...$args): void
    {
        if (!isset($this->eventListeners[$event])) {
            return;
        }

        foreach ($this->eventListeners[$event] as $callback) {
            try {
                $callback(...$args);
            } catch (Throwable $e) {
                $this->logger->error('Error in WebSocket event handler', [
                    'event' => $event,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    public function addConnection(Connection $conn): void
    {
        $this->connections[$conn->getId()] = $conn;
        $this->emit('connect', $conn);
    }

    public function removeConnection(Connection $conn): void
    {
        $id = $conn->getId();

        foreach ($conn->getRooms() as $room) {
            $this->removeConnectionFromRoom($conn, $room);
        }

        unset($this->connections[$id]);
    }

    public function getConnection(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    /**
     * @return array<string, Connection>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * @param array<mixed>|string $data
     */
    public function broadcast(string|array $data, ?Connection $exclude = null): void
    {
        foreach ($this->connections as $conn) {
            if ($exclude !== null && $conn->getId() === $exclude->getId()) {
                continue;
            }

            if ($conn->isOpen()) {
                $conn->send($data);
            }
        }
    }

    /**
     * @param array<mixed>|string $data
     */
    public function broadcastToRoom(string $room, string|array $data, ?Connection $exclude = null): void
    {
        if (!isset($this->rooms[$room])) {
            return;
        }

        foreach ($this->rooms[$room] as $conn) {
            if ($exclude !== null && $conn->getId() === $exclude->getId()) {
                continue;
            }

            if ($conn->isOpen()) {
                $conn->send($data);
            }
        }
    }

    public function addConnectionToRoom(Connection $conn, string $room): void
    {
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
        }

        $this->rooms[$room][$conn->getId()] = $conn;
    }

    public function removeConnectionFromRoom(Connection $conn, string $room): void
    {
        if (isset($this->rooms[$room][$conn->getId()])) {
            unset($this->rooms[$room][$conn->getId()]);

            if ($this->rooms[$room] === []) {
                unset($this->rooms[$room]);
            }
        }
    }

    /**
     * @return array<string, Connection>
     */
    public function getRoomConnections(string $room): array
    {
        return $this->rooms[$room] ?? [];
    }

    public function getRoomCount(string $room): int
    {
        return count($this->rooms[$room] ?? []);
    }

    public function processPings(): void
    {
        if (!$this->config->autoPing) {
            return;
        }

        $now = microtime(true);

        foreach ($this->connections as $conn) {
            if (!$conn->isOpen()) {
                continue;
            }

            $lastPing = $conn->getLastPing();
            $lastPong = $conn->getLastPong();

            if ($lastPing !== null) {
                $timeSincePing = $now - $lastPing;
                $timeSincePong = $now - $lastPong;

                if ($timeSincePing > $this->config->pongTimeout && $timeSincePong > $timeSincePing) {
                    $this->logger->warning('Connection pong timeout', [
                        'conn_id' => $conn->getId(),
                        'last_ping' => $lastPing,
                        'last_pong' => $lastPong,
                    ]);
                    $conn->close(CloseCode::POLICY_VIOLATION->value, 'Pong timeout');
                    continue;
                }
            }

            if ($lastPing === null || $now - $lastPing > $this->config->pingInterval) {
                $conn->ping();
            }
        }
    }

    public function cleanupClosedConnections(): int
    {
        $removed = 0;

        foreach ($this->connections as $conn) {
            if ($conn->getState() === ConnectionState::CLOSED) {
                $this->removeConnection($conn);
                $removed++;
            }
        }

        return $removed;
    }

    public function closeAll(int $code = CloseCode::GOING_AWAY->value, string $reason = 'Server shutdown'): void
    {
        foreach ($this->connections as $conn) {
            $conn->close($code, $reason);
        }
    }

    public function handleConnectionError(Connection $conn, Throwable $error): void
    {
        $this->logger->error('WebSocket connection error', [
            'conn_id' => $conn->getId(),
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);

        $this->emit('error', $conn, $error);
    }

    public function getConfig(): WebSocketConfig
    {
        return $this->config;
    }
}
