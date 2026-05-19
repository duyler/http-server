<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Override;
use Socket;

final class ExistingSocket implements SocketInterface
{
    private bool $closed = false;

    public function __construct(
        private readonly Socket $socket,
    ) {}

    #[Override]
    public function bind(string $address, int $port): void
    {
        throw new SocketException('Cannot bind existing socket');
    }

    #[Override]
    public function listen(int $backlog = 511): void
    {
        throw new SocketException('Cannot listen on existing socket');
    }

    #[Override]
    public function accept(): SocketResourceInterface|false
    {
        if ($this->closed) {
            return false;
        }

        $client = socket_accept($this->socket);

        if (false === $client) {
            $error = socket_last_error($this->socket);

            if (SOCKET_EAGAIN === $error || SOCKET_EWOULDBLOCK === $error || 0 === $error) {
                return false;
            }

            return false;
        }

        socket_set_nonblock($client);
        socket_set_option($client, SOL_TCP, TCP_NODELAY, 1);

        return new StreamSocketResource($client);
    }

    #[Override]
    public function read(int $length): string|false
    {
        if ($this->closed) {
            return false;
        }

        $data = socket_read($this->socket, $length, PHP_BINARY_READ);
        return false === $data ? false : $data;
    }

    #[Override]
    public function write(string $data): int|false
    {
        if ($this->closed) {
            return false;
        }

        $result = socket_write($this->socket, $data, strlen($data));
        return false === $result ? false : $result;
    }

    #[Override]
    public function close(): void
    {
        if (false === $this->closed) {
            socket_close($this->socket);
        }
        $this->closed = true;
    }

    #[Override]
    public function isValid(): bool
    {
        return false === $this->closed;
    }

    #[Override]
    public function setBlocking(bool $blocking): void
    {
        if ($this->closed) {
            return;
        }

        $blocking
            ? socket_set_block($this->socket)
            : socket_set_nonblock($this->socket);
    }

    #[Override]
    public function getInternalResource(): mixed
    {
        return $this->socket;
    }
}
