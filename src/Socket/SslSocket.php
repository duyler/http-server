<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Constants;
use Duyler\HttpServer\Exception\SocketException;
use Override;

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

    #[Override]
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

    #[Override]
    public function listen(int $backlog = Constants::DEFAULT_LISTEN_BACKLOG): void
    {
        if (!$this->isBound) {
            throw new SocketException('SSL socket is already listening after bind');
        }
    }

    #[Override]
    public function accept(): SocketResourceInterface|false
    {
        if (!$this->isListening) {
            throw new SocketException('Socket must be listening before accepting connections');
        }

        assert($this->socket !== null);
        $client = stream_socket_accept($this->socket, 0);

        if ($client === false) {
            return false;
        }

        stream_set_blocking($client, false);

        return new StreamSocketResource($client);
    }

    #[Override]
    public function setBlocking(bool $blocking): void
    {
        if (!$this->isValid()) {
            throw new SocketException('Socket is not valid');
        }

        assert($this->socket !== null);
        if (!stream_set_blocking($this->socket, $blocking)) {
            throw new SocketException('Failed to set blocking mode on SSL socket');
        }
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

        assert($this->socket !== null);
        $data = fread($this->socket, $length);
        return $data === false ? false : $data;
    }

    #[Override]
    public function write(string $data): int|false
    {
        if (!$this->isValid()) {
            return false;
        }

        assert($this->socket !== null);
        $written = fwrite($this->socket, $data);
        if ($written !== false) {
            fflush($this->socket);
        }
        return $written;
    }

    #[Override]
    public function close(): void
    {
        if ($this->isValid()) {
            assert($this->socket !== null);
            $socket = $this->socket;
            $this->socket = null;
            fclose($socket);
            $this->isBound = false;
            $this->isListening = false;
        }
    }

    #[Override]
    public function isValid(): bool
    {
        return is_resource($this->socket);
    }

    #[Override]
    public function getInternalResource(): mixed
    {
        return $this->socket;
    }
}
