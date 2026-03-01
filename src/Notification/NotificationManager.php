<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Socket;

final class NotificationManager
{
    private ?Socket $notifyReadSocket = null;
    private ?Socket $notifyWriteSocket = null;
    private mixed $notifySocket = null;
    private bool $notificationEnabled = false;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function enable(): void
    {
        if ($this->notificationEnabled) {
            return;
        }

        $sockets = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        if (false === $result) {
            throw new RuntimeException(
                'Failed to create notification socket pair: '
                . socket_strerror(socket_last_error()),
            );
        }

        [$this->notifyReadSocket, $this->notifyWriteSocket] = $sockets;

        $this->notifySocket = $this->notifyWriteSocket;
        $this->notificationEnabled = true;
    }

    public function disable(): void
    {
        if (null !== $this->notifyReadSocket) {
            socket_close($this->notifyReadSocket);
            $this->notifyReadSocket = null;
        }

        if (null !== $this->notifyWriteSocket) {
            socket_close($this->notifyWriteSocket);
            $this->notifyWriteSocket = null;
        }

        $this->notifySocket = null;
        $this->notificationEnabled = false;

        $this->logger->info('Notification sockets disabled');
    }

    public function isEnabled(): bool
    {
        return $this->notificationEnabled;
    }

    /**
     * Get read socket for EvIo watcher.
     *
     * Use this socket to monitor notification events.
     * Socket is in blocking mode - call socket_set_nonblock() after export.
     */
    public function getReadSocket(): ?Socket
    {
        return $this->notifyReadSocket;
    }

    /**
     * @return Socket|resource|null
     */
    public function getNotifySocket(): mixed
    {
        /** @var Socket|resource|null */
        return $this->notifySocket;
    }

    public function notify(): void
    {
        if (null === $this->notifySocket) {
            return;
        }

        error_clear_last();

        if ($this->notifySocket instanceof Socket) {
            socket_write($this->notifySocket, 'x', 1);
        } else {
            /** @var resource $this->notifySocket */
            fwrite($this->notifySocket, 'x');
        }
    }

    public function reset(): void
    {
        $this->disable();
    }
}
