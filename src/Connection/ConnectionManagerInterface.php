<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Duyler\HttpServer\Socket\SocketResourceInterface;

interface ConnectionManagerInterface
{
    public function add(ConnectionInterface $connection): void;

    public function remove(ConnectionInterface $connection): void;

    public function findBySocket(SocketResourceInterface $socket): ?ConnectionInterface;

    /**
     * @return array<ConnectionInterface>
     */
    public function getAll(): array;

    public function count(): int;

    public function closeAll(): void;

    public function removeTimedOut(int $timeout): int;

    public function closeConnectionWithMetrics(ConnectionInterface $connection): void;
}
