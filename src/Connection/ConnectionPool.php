<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Socket;
use SplObjectStorage;

class ConnectionPool
{
    /** @var SplObjectStorage<Connection, true> */
    private SplObjectStorage $connections;

    /** @var array<int, Connection> */
    private array $connectionsByResourceId = [];

    public function __construct(
        private readonly int $maxConnections = 1000,
    ) {
        $this->connections = new SplObjectStorage();
    }

    public function add(Connection $connection): void
    {
        if ($this->connections->count() >= $this->maxConnections) {
            $connection->close();
            return;
        }

        $this->connections->attach($connection);
        $resourceId = $this->getSocketId($connection->getSocket());
        $this->connectionsByResourceId[$resourceId] = $connection;
    }

    public function remove(Connection $connection): void
    {
        if ($this->connections->contains($connection)) {
            $this->connections->detach($connection);
            $resourceId = $this->getSocketId($connection->getSocket());
            unset($this->connectionsByResourceId[$resourceId]);
        }
    }

    /**
     * @param resource|Socket $socket
     */
    public function findBySocket(mixed $socket): ?Connection
    {
        $resourceId = $this->getSocketId($socket);
        return $this->connectionsByResourceId[$resourceId] ?? null;
    }

    /**
     * @param resource|Socket $socket
     */
    private function getSocketId(mixed $socket): int
    {
        if ($socket instanceof Socket) {
            return spl_object_id($socket);
        }

        if (is_resource($socket)) {
            return get_resource_id($socket);
        }

        return 0;
    }

    /**
     * @return array<Connection>
     */
    public function getAll(): array
    {
        $connections = [];
        foreach ($this->connections as $connection) {
            $connections[] = $connection;
        }
        return $connections;
    }

    public function count(): int
    {
        return $this->connections->count();
    }

    public function removeTimedOut(int $timeout): int
    {
        $removed = 0;
        $toRemove = [];

        foreach ($this->connections as $connection) {
            if ($connection->isTimedOut($timeout)) {
                $toRemove[] = $connection;
            }
        }

        foreach ($toRemove as $connection) {
            $connection->close();
            $this->remove($connection);
            ++$removed;
        }

        return $removed;
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections->removeAll($this->connections);
        $this->connectionsByResourceId = [];
    }
}
