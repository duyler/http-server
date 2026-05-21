<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Socket;

interface SocketResourceInterface
{
    public function read(int $length): string|false;

    public function write(string $data): int|false;

    public function close(): void;

    public function isValid(): bool;

    public function setBlocking(bool $blocking): void;

    /**
     * @return Socket|resource|null
     */
    public function getInternalResource(): mixed;

    /**
     * Get remote peer address information
     *
     * @return array{ip: string, port: int}|false
     */
    public function getPeerName(): array|false;

    /**
     * Export socket resource as stream
     *
     * @return resource|false
     */
    public function exportStream(): mixed;
}
