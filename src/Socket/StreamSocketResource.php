<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Exception\SocketException;
use InvalidArgumentException;
use Override;
use Socket;
use Throwable;

final class StreamSocketResource implements SocketResourceInterface
{
    private bool $closed = false;

    /**
     * @var Socket|resource|null
     */
    private mixed $resource = null;

    /**
     * @param Socket|resource $resource
     */
    public function __construct(mixed $resource)
    {
        if (!is_resource($resource) && !$resource instanceof Socket) {
            throw new InvalidArgumentException('Invalid socket resource or Socket object');
        }
        $this->resource = $resource;
    }

    #[Override]
    public function read(int $length): string|false
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($length < 1) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            $data = socket_read($this->resource, $length, PHP_BINARY_READ);
            return $data === false ? false : $data;
        }

        assert(is_resource($this->resource));
        $data = fread($this->resource, $length);
        return $data === false ? false : $data;
    }

    #[Override]
    public function write(string $data): int|false
    {
        if (!$this->isValid()) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            $result = socket_write($this->resource, $data, strlen($data));
            return $result === false ? false : $result;
        }

        assert(is_resource($this->resource));
        $written = fwrite($this->resource, $data);
        if ($written !== false) {
            fflush($this->resource);
        }
        return $written;
    }

    #[Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            if ($this->resource instanceof Socket) {
                socket_close($this->resource);
            } elseif (is_resource($this->resource)) {
                $resource = $this->resource;
                $this->resource = null;
                fclose($resource);
            }
        } catch (Throwable) {
        }

        $this->resource = null;
        $this->closed = true;
    }

    #[Override]
    public function isValid(): bool
    {
        if ($this->closed) {
            return false;
        }

        if ($this->resource instanceof Socket) {
            return true;
        }

        return is_resource($this->resource);
    }

    #[Override]
    public function setBlocking(bool $blocking): void
    {
        if (!$this->isValid()) {
            throw new SocketException('Cannot set blocking mode on invalid socket');
        }

        if ($this->resource instanceof Socket) {
            $success = $blocking
                ? socket_set_block($this->resource)
                : socket_set_nonblock($this->resource);

            if (!$success) {
                throw new SocketException(
                    sprintf('Failed to set blocking mode: %s', socket_strerror(socket_last_error($this->resource))),
                );
            }
            return;
        }

        assert(is_resource($this->resource));
        if (!stream_set_blocking($this->resource, $blocking)) {
            throw new SocketException('Failed to set blocking mode on stream');
        }
    }

    /**
     * @return Socket|resource|null
     */
    #[Override]
    public function getInternalResource(): mixed
    {
        return $this->resource;
    }
}
