<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Constants;
use Duyler\HttpServer\Exception\SocketException;
use Socket;

class StreamSocket implements SocketInterface
{
    /** @var resource|null */
    private mixed $socket = null;
    private bool $isBound = false;
    private bool $isListening = false;

    public function __construct(
        protected readonly bool $ipv6 = false,
    ) {}

    public function bind(string $address, int $port): void
    {
        $domain = $this->ipv6 ? AF_INET6 : AF_INET;

        $socket = socket_create($domain, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new SocketException(
                sprintf('Failed to create socket: %s', socket_strerror(socket_last_error())),
            );
        }

        $this->socket = $socket;

        if (!socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            throw new SocketException(
                sprintf('Failed to set socket option SO_REUSEADDR: %s', socket_strerror(socket_last_error($this->socket))),
            );
        }

        if (!socket_bind($this->socket, $address, $port)) {
            $error = socket_strerror(socket_last_error($this->socket));
            $this->close();
            throw new SocketException(sprintf('Failed to bind socket to %s:%d - %s', $address, $port, $error));
        }

        $this->isBound = true;
    }

    public function listen(int $backlog = Constants::DEFAULT_LISTEN_BACKLOG): void
    {
        if (!$this->isBound) {
            throw new SocketException('Socket must be bound before listening');
        }

        if (!socket_listen($this->socket, $backlog)) {
            throw new SocketException(
                sprintf('Failed to listen on socket: %s', socket_strerror(socket_last_error($this->socket))),
            );
        }

        $this->isListening = true;
    }

    public function accept(): mixed
    {
        if (!$this->isListening) {
            throw new SocketException('Socket must be listening before accepting connections');
        }

        $client = socket_accept($this->socket);

        if ($client === false) {
            $error = socket_last_error($this->socket);

            if ($error === SOCKET_EAGAIN || $error === SOCKET_EWOULDBLOCK || $error === 0) {
                return false;
            }

            throw new SocketException(
                sprintf('Failed to accept connection: %s', socket_strerror($error)),
            );
        }

        socket_set_nonblock($client);

        return $client;
    }

    public function setBlocking(bool $blocking): void
    {
        if (!$this->isValid()) {
            throw new SocketException('Socket is not valid');
        }

        $result = $blocking
            ? socket_set_block($this->socket)
            : socket_set_nonblock($this->socket);

        if (!$result) {
            throw new SocketException(
                sprintf('Failed to set blocking mode: %s', socket_strerror(socket_last_error($this->socket))),
            );
        }
    }

    public function close(): void
    {
        if ($this->isValid()) {
            socket_close($this->socket);
            $this->socket = null;
            $this->isBound = false;
            $this->isListening = false;
        }
    }

    public function getResource(): mixed
    {
        return $this->socket;
    }

    public function isValid(): bool
    {
        return is_resource($this->socket) || $this->socket instanceof Socket;
    }
}
