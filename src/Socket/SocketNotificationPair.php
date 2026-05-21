<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Error;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

final class SocketNotificationPair implements NotificationSocketPairInterface
{
    use SocketErrorSuppressor;

    private ?Socket $readSocket = null;
    private ?Socket $writeSocket = null;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function createPair(): void
    {
        $this->close();

        $sockets = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        if (false === $result) {
            throw SocketException::fromLastError();
        }

        $this->readSocket = $sockets[0];
        $this->writeSocket = $sockets[1];

        socket_set_nonblock($this->readSocket);
        socket_set_nonblock($this->writeSocket);
    }

    #[Override]
    public function getReadSocket(): ?Socket
    {
        return $this->readSocket;
    }

    #[Override]
    public function getWriteSocket(): ?Socket
    {
        return $this->writeSocket;
    }

    #[Override]
    public function notify(): void
    {
        if (null === $this->writeSocket) {
            return;
        }

        $socket = $this->writeSocket;

        try {
            $result = $this->suppressSocketWarnings(fn(): int|false => socket_write($socket, 'x', 1));
        } catch (Error) {
            $result = false;
        }

        if (false === $result) {
            try {
                $error = socket_strerror(socket_last_error($socket));
            } catch (Error) {
                $error = 'Socket closed';
            }

            $this->logger->warning('Failed to write notification byte: ' . $error);
        }
    }

    #[Override]
    public function close(): void
    {
        if (null !== $this->readSocket) {
            try {
                socket_close($this->readSocket);
            } catch (Error) {
            }
            $this->readSocket = null;
        }

        if (null !== $this->writeSocket) {
            try {
                socket_close($this->writeSocket);
            } catch (Error) {
            }
            $this->writeSocket = null;
        }
    }

    #[Override]
    public function isEnabled(): bool
    {
        return null !== $this->readSocket;
    }
}
