<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Duyler\HttpServer\Socket\SocketResourceInterface;
use Override;

final class Connection implements ConnectionInterface
{
    /** @var array<int, string> */
    private array $chunks = [];
    private int $bufferSize = 0;
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
        private readonly int $maxBufferSize = 10485760,
    ) {
        $this->lastActivityTime = microtime(true);
    }

    #[Override]
    public function getSocket(): SocketResourceInterface
    {
        return $this->socket;
    }

    #[Override]
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    #[Override]
    public function getRemotePort(): int
    {
        return $this->remotePort;
    }

    #[Override]
    public function getBuffer(): string
    {
        return implode('', $this->chunks);
    }

    #[Override]
    public function appendToBuffer(string $data): void
    {
        $this->chunks[] = $data;
        $this->bufferSize += strlen($data);

        if ($this->bufferSize > $this->maxBufferSize) {
            $this->close();
            return;
        }

        $this->updateActivity();
    }

    #[Override]
    public function clearBuffer(): void
    {
        $this->chunks = [];
        $this->bufferSize = 0;
        $this->clearRequestCache();
    }

    #[Override]
    public function consumeBuffer(int $bytes): void
    {
        if ($bytes >= $this->bufferSize) {
            $this->chunks = [];
            $this->bufferSize = 0;
        } else {
            $remaining = $bytes;
            while ($remaining > 0 && [] !== $this->chunks) {
                $chunkLen = strlen($this->chunks[0]);
                if ($chunkLen <= $remaining) {
                    $remaining -= $chunkLen;
                    array_shift($this->chunks);
                } else {
                    $this->chunks[0] = substr($this->chunks[0], $remaining);
                    $remaining = 0;
                }
            }
            $this->bufferSize -= $bytes;
        }
        $this->clearRequestCache();
    }

    /**
     * @return array<string, string|array<int, string>>|null
     */
    #[Override]
    public function getCachedHeaders(): ?array
    {
        /** @var array<string, string|array<int, string>>|null */
        return $this->cachedHeaders;
    }

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    #[Override]
    public function setCachedHeaders(array $headers): void
    {
        $this->cachedHeaders = $headers;
    }

    #[Override]
    public function getExpectedContentLength(): ?int
    {
        return $this->expectedContentLength;
    }

    #[Override]
    public function setExpectedContentLength(int $length): void
    {
        $this->expectedContentLength = $length;
    }

    #[Override]
    public function startRequestTimer(): void
    {
        if (null === $this->requestStartTime) {
            $this->requestStartTime = microtime(true);
        }
    }

    #[Override]
    public function getRequestStartTime(): ?float
    {
        return $this->requestStartTime;
    }

    #[Override]
    public function isRequestTimedOut(int $timeout): bool
    {
        if (null === $this->requestStartTime) {
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

    #[Override]
    public function incrementRequestCount(): void
    {
        ++$this->requestCount;
    }

    #[Override]
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    #[Override]
    public function updateActivity(): void
    {
        $this->lastActivityTime = microtime(true);
    }

    #[Override]
    public function getLastActivityTime(): float
    {
        return $this->lastActivityTime;
    }

    #[Override]
    public function isTimedOut(int $timeout): bool
    {
        return (microtime(true) - $this->lastActivityTime) > $timeout;
    }

    #[Override]
    public function setKeepAlive(bool $keepAlive): void
    {
        $this->keepAlive = $keepAlive;
    }

    #[Override]
    public function isKeepAlive(): bool
    {
        return $this->keepAlive;
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->socket->close();
        $this->closed = true;
    }

    #[Override]
    public function write(string $data): int|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        $this->updateActivity();
        return $this->socket->write($data);
    }

    #[Override]
    public function read(int $length): string|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        $this->updateActivity();
        return $this->socket->read($length);
    }

    #[Override]
    public function isValid(): bool
    {
        if ($this->closed) {
            return false;
        }

        return $this->socket->isValid();
    }

    #[Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
