<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Duyler\HttpServer\Socket\SocketResourceInterface;

interface ConnectionInterface
{
    public function getSocket(): SocketResourceInterface;

    public function getRemoteAddress(): string;

    public function getRemotePort(): int;

    public function getBuffer(): string;

    public function appendToBuffer(string $data): void;

    public function clearBuffer(): void;

    public function consumeBuffer(int $bytes): void;

    public function incrementRequestCount(): void;

    public function getRequestCount(): int;

    public function updateActivity(): void;

    public function getLastActivityTime(): float;

    public function isTimedOut(int $timeout): bool;

    public function setKeepAlive(bool $keepAlive): void;

    public function isKeepAlive(): bool;

    public function close(): void;

    public function write(string $data): int|false;

    public function read(int $length): string|false;

    public function isValid(): bool;

    public function isClosed(): bool;

    public function startRequestTimer(): void;

    public function getRequestStartTime(): ?float;

    public function isRequestTimedOut(int $timeout): bool;

    /**
     * @return array<string, string|array<int, string>>|null
     */
    public function getCachedHeaders(): ?array;

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function setCachedHeaders(array $headers): void;

    public function getExpectedContentLength(): ?int;

    public function setExpectedContentLength(int $length): void;
}
