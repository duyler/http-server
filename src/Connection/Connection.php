<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Duyler\HttpServer\Socket\SocketResourceInterface;

class Connection
{
    private string $buffer = '';
    private int $requestCount = 0;
    private float $lastActivityTime;
    private bool $keepAlive = false;
    private bool $closed = false;

    /**
     * @var array<string, string|array<int, string>>|null
     */
    private ?array $cachedHeaders = null;
    private ?int $expectedContentLength = null;
    private ?float $requestStartTime = null;

    public function __construct(
        private readonly SocketResourceInterface $socket,
        private readonly string $remoteAddress,
        private readonly int $remotePort,
    ) {
        $this->lastActivityTime = microtime(true);
    }

    public function getSocket(): SocketResourceInterface
    {
        return $this->socket;
    }

    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    public function getRemotePort(): int
    {
        return $this->remotePort;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function appendToBuffer(string $data): void
    {
        $this->buffer .= $data;
        $this->updateActivity();
    }

    public function clearBuffer(): void
    {
        $this->buffer = '';
        $this->clearRequestCache();
    }

    /**
     * @return array<string, string|array<int, string>>|null
     */
    public function getCachedHeaders(): ?array
    {
        /** @var array<string, string|array<int, string>>|null */
        return $this->cachedHeaders;
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function setCachedHeaders(array $headers): void
    {
        $this->cachedHeaders = $headers;
    }

    public function getExpectedContentLength(): ?int
    {
        return $this->expectedContentLength;
    }

    public function setExpectedContentLength(int $length): void
    {
        $this->expectedContentLength = $length;
    }

    public function startRequestTimer(): void
    {
        if ($this->requestStartTime === null) {
            $this->requestStartTime = microtime(true);
        }
    }

    public function getRequestStartTime(): ?float
    {
        return $this->requestStartTime;
    }

    public function isRequestTimedOut(int $timeout): bool
    {
        if ($this->requestStartTime === null) {
            return false;
        }
        return (microtime(true) - $this->requestStartTime) > $timeout;
    }

    private function clearRequestCache(): void
    {
        $this->cachedHeaders = null;
        $this->expectedContentLength = null;
        $this->requestStartTime = null;
    }

    public function incrementRequestCount(): void
    {
        ++$this->requestCount;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    public function updateActivity(): void
    {
        $this->lastActivityTime = microtime(true);
    }

    public function getLastActivityTime(): float
    {
        return $this->lastActivityTime;
    }

    public function isTimedOut(int $timeout): bool
    {
        return (microtime(true) - $this->lastActivityTime) > $timeout;
    }

    public function setKeepAlive(bool $keepAlive): void
    {
        $this->keepAlive = $keepAlive;
    }

    public function isKeepAlive(): bool
    {
        return $this->keepAlive;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->socket->close();
        $this->closed = true;
    }

    public function write(string $data): int|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->updateActivity();
        return $this->socket->write($data);
    }

    public function read(int $length): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->updateActivity();
        return $this->socket->read($length);
    }

    public function isValid(): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->socket->isValid();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
