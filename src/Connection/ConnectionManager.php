<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Connection;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Socket\SocketInterface;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConnectionManager implements ConnectionManagerInterface
{
    public function __construct(
        private readonly ConnectionPool $pool,
        private readonly HttpParser $httpParser,
        private readonly HttpRequestProcessor $requestProcessor,
        private readonly ServerMetrics $metrics,
        private readonly ServerConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Override]
    public function add(ConnectionInterface $connection): void
    {
        $this->pool->add($connection);
    }

    #[Override]
    public function remove(ConnectionInterface $connection): void
    {
        $this->pool->remove($connection);
    }

    #[Override]
    public function findBySocket(SocketResourceInterface $socket): ?ConnectionInterface
    {
        return $this->pool->findBySocket($socket);
    }

    #[Override]
    public function getAll(): array
    {
        return $this->pool->getAll();
    }

    #[Override]
    public function count(): int
    {
        return $this->pool->count();
    }

    #[Override]
    public function closeAll(): void
    {
        $this->pool->closeAll();
    }

    #[Override]
    public function removeTimedOut(int $timeout): int
    {
        return count($this->pool->removeTimedOut($timeout));
    }

    public function closeConnectionWithMetrics(ConnectionInterface $connection): void
    {
        $this->requestProcessor->removeConnectionsByConnection($connection);
        $connection->close();
        $this->pool->remove($connection);
        $this->metrics->incrementClosedConnections();
    }

    public function readFromConnection(
        ConnectionInterface $connection,
        int $bufferSize,
        callable $onDataCallback,
    ): bool {
        if (false === $connection->isValid()) {
            $this->closeConnectionWithMetrics($connection);
            return false;
        }

        $socket = $connection->getSocket();
        $internalResource = $socket instanceof StreamSocketResource
            ? $socket->getInternalResource()
            : null;

        if (null === $internalResource) {
            $this->closeConnectionWithMetrics($connection);
            return false;
        }

        $ready = StreamSocketResource::select([$internalResource]);

        if (null === $ready) {
            return true;
        }

        $data = $connection->read($bufferSize);

        if (false === $data || '' === $data) {
            $this->closeConnectionWithMetrics($connection);
            return false;
        }

        $connection->appendToBuffer($data);

        if ($connection->isClosed()) {
            $this->closeConnectionWithMetrics($connection);
            return false;
        }

        $onDataCallback($connection);

        return true;
    }

    public function readFromConnectionDirect(
        ConnectionInterface $connection,
        int $bufferSize,
        callable $onDataCallback,
    ): void {
        if (false === $connection->isValid()) {
            $this->closeConnectionWithMetrics($connection);
            return;
        }

        $data = $connection->read($bufferSize);

        if (false === $data || '' === $data) {
            $this->closeConnectionWithMetrics($connection);
            return;
        }

        $connection->appendToBuffer($data);

        if ($connection->isClosed()) {
            $this->closeConnectionWithMetrics($connection);
            return;
        }

        $onDataCallback($connection);
    }

    public function acceptFromServerSocket(
        SocketInterface $socket,
        int $maxAccepts,
        bool $debugMode,
    ): int {
        $acceptedCount = 0;

        while ($acceptedCount < $maxAccepts) {
            $clientSocketResource = $socket->accept();

            if (false === $clientSocketResource) {
                break;
            }

            $acceptedCount++;

            $remoteAddr = '0.0.0.0';
            $remotePort = 0;

            $peerInfo = $clientSocketResource->getPeerName();
            if (false !== $peerInfo) {
                $remoteAddr = $peerInfo['ip'];
                $remotePort = $peerInfo['port'];
            }

            $connection = new Connection($clientSocketResource, $remoteAddr, $remotePort, $this->config->maxRequestSize);
            $this->pool->add($connection);
            $this->metrics->incrementTotalConnections();

            if ($debugMode) {
                $this->logger->debug('New connection accepted', [
                    'remote' => "$remoteAddr:$remotePort",
                    'total_connections' => $this->pool->count(),
                    'accepts_this_cycle' => $acceptedCount,
                ]);
            }
        }

        return $acceptedCount;
    }

    public function cleanupTimedOut(int $timeout): int
    {
        $removed = $this->pool->removeTimedOut($timeout);
        foreach ($removed as $connection) {
            $this->requestProcessor->removeConnectionsByConnection($connection);
            $this->metrics->incrementTimedOutConnections();
        }
        return count($removed);
    }

}
