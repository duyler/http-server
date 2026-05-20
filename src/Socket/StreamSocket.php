<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Override;
use Socket;
use Throwable;

final class StreamSocket implements SocketInterface
{
    use SocketErrorSuppressor;

    private ?Socket $socket = null;
    private bool $isBound = false;
    private bool $isListening = false;

    public function __construct(
        protected readonly bool $ipv6 = false,
    ) {}

    #[Override]
    public function bind(string $address, int $port): void
    {
        $domain = $this->ipv6 ? AF_INET6 : AF_INET;

        $socket = socket_create($domain, SOCK_STREAM, SOL_TCP);

        if (false === $socket) {
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

        $socket = $this->socket;
        $result = $this->suppressSocketWarnings(fn(): bool => socket_bind($socket, $address, $port));

        if (false === $result) {
            $error = socket_strerror(socket_last_error($this->socket));
            $this->close();
            throw new SocketException(sprintf('Failed to bind socket to %s:%d - %s', $address, $port, $error));
        }

        $this->isBound = true;
    }

    #[Override]
    public function listen(int $backlog = 511): void
    {
        if (false === $this->isBound) {
            throw new SocketException('Socket must be bound before listening');
        }

        assert($this->socket instanceof Socket);

        if (!socket_listen($this->socket, $backlog)) {
            throw new SocketException(
                sprintf('Failed to listen on socket: %s', socket_strerror(socket_last_error($this->socket))),
            );
        }

        $this->isListening = true;
    }

    #[Override]
    public function accept(): SocketResourceInterface|false
    {
        if (false === $this->isListening) {
            throw new SocketException('Socket must be listening before accepting connections');
        }

        assert($this->socket instanceof Socket);

        $client = socket_accept($this->socket);

        if (false === $client) {
            $error = socket_last_error($this->socket);

            if (SOCKET_EAGAIN === $error || SOCKET_EWOULDBLOCK === $error || 0 === $error) {
                return false;
            }

            throw new SocketException(
                sprintf('Failed to accept connection: %s', socket_strerror($error)),
            );
        }

        return StreamSocketResource::configureClient($client);
    }

    #[Override]
    public function setBlocking(bool $blocking): void
    {
        if (false === $this->isValid()) {
            throw new SocketException('Socket is not valid');
        }

        assert($this->socket instanceof Socket);

        $result = $blocking
            ? socket_set_block($this->socket)
            : socket_set_nonblock($this->socket);

        if (false === $result) {
            throw new SocketException(
                sprintf('Failed to set blocking mode: %s', socket_strerror(socket_last_error($this->socket))),
            );
        }
    }

    #[Override]
    public function read(int $length): string|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        if (1 > $length) {
            return false;
        }

        assert($this->socket instanceof Socket);

        $data = socket_read($this->socket, $length, PHP_BINARY_READ);
        return $data === false ? false : $data;
    }

    #[Override]
    public function write(string $data): int|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        assert($this->socket instanceof Socket);

        $result = socket_write($this->socket, $data, strlen($data));
        return $result === false ? false : $result;
    }

    #[Override]
    public function close(): void
    {
        if ($this->isValid()) {
            assert($this->socket instanceof Socket);
            socket_close($this->socket);
            $this->socket = null;
            $this->isBound = false;
            $this->isListening = false;
        }
    }

    #[Override]
    public function isValid(): bool
    {
        return $this->socket instanceof Socket;
    }

    #[Override]
    public function getInternalResource(): mixed
    {
        return $this->socket;
    }

    #[Override]
    public function getPeerName(): array|false
    {
        if (false === $this->isValid()) {
            return false;
        }

        assert($this->socket instanceof Socket);

        $ip = '';
        $port = 0;
        $result = socket_getpeername($this->socket, $ip, $port);

        if (false === $result) {
            return false;
        }

        return ['ip' => $ip, 'port' => $port];
    }

    #[Override]
    public function exportStream(): mixed
    {
        if (false === $this->isValid()) {
            return false;
        }

        assert($this->socket instanceof Socket);

        try {
            error_clear_last();
            socket_set_block($this->socket);
        } catch (Throwable) {
            return false;
        }

        $stream = socket_export_stream($this->socket);
        socket_set_nonblock($this->socket);

        return $stream;
    }
}
