<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Duyler\HttpServer\Socket\SocketResourceInterface;
use IteratorAggregate;
use Override;
use SplObjectStorage;
use Traversable;

/**
 * @implements IteratorAggregate<int, ConnectionInterface>
 */
final class ConnectionPool implements IteratorAggregate
{
    /** @var SplObjectStorage<ConnectionInterface, int> */
    private SplObjectStorage $connections;

    /** @var array<int, ConnectionInterface> */
    private array $connectionsByResourceId = [];

    /** @var array<string, ConnectionInterface> */
    private array $connectionsByAddress = [];

    private bool $isModifying = false;

    public function __construct(
        private readonly int $maxConnections = 1000,
    ) {
        $this->connections = new SplObjectStorage();
    }

    #[Override]
    public function getIterator(): Traversable
    {
        foreach ($this->connections as $connection) {
            yield $connection;
        }
    }

    public function add(ConnectionInterface $connection): void
    {
        if ($this->isModifying) {
            $connection->close();
            return;
        }

        $this->isModifying = true;

        try {
            if ($this->maxConnections <= $this->connections->count()) {
                $connection->close();
                return;
            }

            $this->connections->offsetSet($connection, time());

            $resourceId = $this->getSocketId($connection->getSocket());
            $this->connectionsByResourceId[$resourceId] = $connection;

            $address = $connection->getRemoteAddress();
            if ('' !== $address) {
                $this->connectionsByAddress[$address] = $connection;
            }
        } finally {
            $this->isModifying = false;
        }
    }

    public function remove(ConnectionInterface $connection): void
    {
        if ($this->isModifying) {
            return;
        }

        $this->isModifying = true;

        try {
            if ($this->connections->offsetExists($connection)) {
                $this->connections->offsetUnset($connection);

                $resourceId = $this->getSocketId($connection->getSocket());
                unset($this->connectionsByResourceId[$resourceId]);

                $address = $connection->getRemoteAddress();
                if (isset($this->connectionsByAddress[$address])) {
                    unset($this->connectionsByAddress[$address]);
                }
            }
        } finally {
            $this->isModifying = false;
        }
    }

    public function findBySocket(SocketResourceInterface $socket): ?ConnectionInterface
    {
        $resourceId = $this->getSocketId($socket);
        return $this->connectionsByResourceId[$resourceId] ?? null;
    }

    public function findByAddress(string $address): ?ConnectionInterface
    {
        return $this->connectionsByAddress[$address] ?? null;
    }

    private function getSocketId(SocketResourceInterface $socket): int
    {
        return spl_object_id($socket);
    }

    /**
     * @return array<ConnectionInterface>
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
            $now = time();
            $toRemove = [];

            foreach ($this->connections as $connection) {
                $addedAt = $this->connections[$connection];

                if ($connection->isTimedOut($timeout) || $timeout < ($now - $addedAt)) {
                    $toRemove[] = $connection;
                }
            }

            foreach ($toRemove as $connection) {
                $connection->close();

                if ($this->connections->offsetExists($connection)) {
                    $this->connections->offsetUnset($connection);

                    $resourceId = $this->getSocketId($connection->getSocket());
                    unset($this->connectionsByResourceId[$resourceId]);

                    $address = $connection->getRemoteAddress();
                    if (isset($this->connectionsByAddress[$address])) {
                        unset($this->connectionsByAddress[$address]);
                    }

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
        $this->connectionsByAddress = [];
    }

    public function has(ConnectionInterface $connection): bool
    {
        return $this->connections->offsetExists($connection);
    }

    public function isFull(): bool
    {
        return $this->maxConnections <= $this->connections->count();
    }

    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }
}
