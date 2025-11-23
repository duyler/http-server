<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Socket;
use Throwable;

class Connection
{
    private string $buffer = '';
    private int $requestCount = 0;
    private float $lastActivityTime;
    private bool $keepAlive = false;
    private bool $closed = false;

    /** @var array<string, string|array<int, string>>|null */
    private ?array $cachedHeaders = null;
    private ?int $expectedContentLength = null;
    private ?float $requestStartTime = null;

    /**
     * @param resource $socket
     */
    public function __construct(
        private readonly mixed $socket,
        private readonly string $remoteAddress,
        private readonly int $remotePort,
    ) {
        $this->lastActivityTime = microtime(true);
    }

    /**
     * @return resource
     */
    public function getSocket(): mixed
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

        if (is_resource($this->socket)) {
            try {
                fclose($this->socket);
            } catch (Throwable) {
            }
            $this->closed = true;
        } elseif ($this->socket instanceof Socket) {
            try {
                socket_close($this->socket);
                $this->closed = true;
            } catch (Throwable) {
                $this->closed = true;
            }
        }
    }

    public function write(string $data): int|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->updateActivity();

        if ($this->socket instanceof Socket) {
            $result = socket_write($this->socket, $data, strlen($data));
            return $result === false ? false : $result;
        }

        $written = fwrite($this->socket, $data);
        if ($written !== false) {
            fflush($this->socket);
        }
        return $written;
    }

    public function read(int $length): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        $this->updateActivity();

        if ($this->socket instanceof Socket) {
            $data = socket_read($this->socket, $length, PHP_BINARY_READ);
            return $data === false ? false : $data;
        }

        if ($length < 1) {
            return false;
        }

        $data = fread($this->socket, $length);
        return $data === false ? false : $data;
    }

    public function isValid(): bool
    {
        if ($this->closed) {
            return false;
        }

        return is_resource($this->socket) || $this->socket instanceof Socket;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
