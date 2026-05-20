<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Notification;

use Duyler\HttpServer\Socket\NotificationSocketPairInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

final readonly class NotificationManager
{
    public function __construct(
        private NotificationSocketPairInterface $socketPair,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function enable(): void
    {
        if ($this->socketPair->isEnabled()) {
            return;
        }

        $this->socketPair->createPair();
    }

    public function disable(): void
    {
        $this->socketPair->close();

        $this->logger->info('Notification sockets disabled');
    }

    public function isEnabled(): bool
    {
        return $this->socketPair->isEnabled();
    }

    public function getReadSocket(): ?Socket
    {
        return $this->socketPair->getReadSocket();
    }

    public function getNotifySocket(): ?Socket
    {
        return $this->socketPair->getWriteSocket();
    }

    public function notify(): void
    {
        $this->socketPair->notify();
    }

    public function reset(): void
    {
        $this->disable();
    }
}
