<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\IPC;

use Duyler\HttpServer\WorkerPool\Exception\IPCException;
use Socket;

class UnixSocketChannel
{
    private ?Socket $socket = null;
    private bool $isConnected = false;

    public function __construct(private readonly string $socketPath, private readonly bool $isServer = false) {}

    public function connect(): bool
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if ($socket === false) {
            throw new IPCException('Failed to create Unix socket: ' . socket_strerror(socket_last_error()));
        }
        $this->socket = $socket;

        if ($this->isServer) {
            if (file_exists($this->socketPath)) {
                unlink($this->socketPath);
            }

            if (!socket_bind($this->socket, $this->socketPath)) {
                throw new IPCException('Failed to bind Unix socket: ' . socket_strerror(socket_last_error($this->socket)));
            }

            if (!socket_listen($this->socket)) {
                throw new IPCException('Failed to listen on Unix socket: ' . socket_strerror(socket_last_error($this->socket)));
            }
        } else {
            if (!socket_connect($this->socket, $this->socketPath)) {
                throw new IPCException('Failed to connect to Unix socket: ' . socket_strerror(socket_last_error($this->socket)));
            }
        }

        socket_set_nonblock($this->socket);
        $this->isConnected = true;

        return true;
    }

    public function accept(): ?Socket
    {
        if (!$this->isServer || $this->socket === null) {
            throw new IPCException('Cannot accept on non-server socket');
        }

        $clientSocket = socket_accept($this->socket);

        if ($clientSocket === false) {
            return null;
        }

        return $clientSocket;
    }

    public function send(Message $message): bool
    {
        if ($this->socket === null || !$this->isConnected) {
            throw new IPCException('Socket is not connected');
        }

        $data = $message->serialize();
        $length = strlen($data);

        $header = pack('N', $length);
        $packet = $header . $data;

        $written = socket_write($this->socket, $packet, strlen($packet));

        return $written !== false && $written > 0;
    }

    public function receive(): ?Message
    {
        if ($this->socket === null || !$this->isConnected) {
            throw new IPCException('Socket is not connected');
        }

        $lengthData = socket_read($this->socket, 4, PHP_BINARY_READ);

        if ($lengthData === false || $lengthData === '') {
            return null;
        }

        if (strlen($lengthData) < 4) {
            return null;
        }

        $unpacked = unpack('N', $lengthData);
        if ($unpacked === false || !isset($unpacked[1])) {
            return null;
        }

        $length = $unpacked[1];
        assert(is_int($length));

        if ($length === 0 || $length > 1048576) {
            throw new IPCException('Invalid message length: ' . $length);
        }

        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = socket_read($this->socket, $remaining, PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return Message::unserialize($data);
    }

    public function getSocket(): ?Socket
    {
        return $this->socket;
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
            $this->isConnected = false;
        }

        if ($this->isServer && file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
