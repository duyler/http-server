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

    private bool $isModifying = false;

    public function __construct(
        private readonly int $maxConnections = 1000,
    ) {
        $this->connections = new SplObjectStorage();
    }

    public function add(Connection $connection): void
    {
        if ($this->isModifying) {
            $connection->close();
            return;
        }

        $this->isModifying = true;

        try {
            if ($this->connections->count() >= $this->maxConnections) {
                $connection->close();
                return;
            }

            $this->connections->attach($connection);
            $resourceId = $this->getSocketId($connection->getSocket());
            $this->connectionsByResourceId[$resourceId] = $connection;
        } finally {
            $this->isModifying = false;
        }
    }

    public function remove(Connection $connection): void
    {
        if ($this->isModifying) {
            return;
        }

        $this->isModifying = true;

        try {
            if ($this->connections->contains($connection)) {
                $this->connections->detach($connection);
                $resourceId = $this->getSocketId($connection->getSocket());
                unset($this->connectionsByResourceId[$resourceId]);
            }
        } finally {
            $this->isModifying = false;
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
        if ($this->isModifying) {
            return 0;
        }

        $this->isModifying = true;

        try {
            $removed = 0;
            $toRemove = [];

            foreach ($this->connections as $connection) {
                if ($connection->isTimedOut($timeout)) {
                    $toRemove[] = $connection;
                }
            }

            foreach ($toRemove as $connection) {
                $connection->close();

                if ($this->connections->contains($connection)) {
                    $this->connections->detach($connection);
                    $resourceId = $this->getSocketId($connection->getSocket());
                    unset($this->connectionsByResourceId[$resourceId]);
                    ++$removed;
                }
            }

            return $removed;
        } finally {
            $this->isModifying = false;
        }
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
