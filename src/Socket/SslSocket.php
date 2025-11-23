<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Constants;
use Duyler\HttpServer\Exception\SocketException;

class SslSocket implements SocketInterface
{
    /** @var resource|null */
    private mixed $socket = null;
    private bool $isBound = false;
    private bool $isListening = false;

    public function __construct(
        private readonly string $certPath,
        private readonly string $keyPath,
        private readonly bool $ipv6 = false,
    ) {}

    public function bind(string $address, int $port): void
    {
        $protocol = $this->ipv6 ? 'ssl://[' . $address . ']' : 'ssl://' . $address;
        $uri = $protocol . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $this->certPath,
                'local_pk' => $this->keyPath,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = stream_socket_server(
            $uri,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if ($socket === false) {
            throw new SocketException(
                sprintf('Failed to create SSL socket on %s: [%d] %s', $uri, $errno, $errstr),
            );
        }

        $this->socket = $socket;
        $this->isBound = true;
        $this->isListening = true;
    }

    public function listen(int $backlog = Constants::DEFAULT_LISTEN_BACKLOG): void
    {
        if (!$this->isBound) {
            throw new SocketException('SSL socket is already listening after bind');
        }
    }

    public function accept(): mixed
    {
        if (!$this->isListening) {
            throw new SocketException('Socket must be listening before accepting connections');
        }

        $client = stream_socket_accept($this->socket, 0);

        if ($client === false) {
            return false;
        }

        stream_set_blocking($client, false);

        return $client;
    }

    public function setBlocking(bool $blocking): void
    {
        if (!$this->isValid()) {
            throw new SocketException('Socket is not valid');
        }

        if (!stream_set_blocking($this->socket, $blocking)) {
            throw new SocketException('Failed to set blocking mode on SSL socket');
        }
    }

    public function close(): void
    {
        if ($this->isValid()) {
            fclose($this->socket);
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
        return is_resource($this->socket);
    }
}
