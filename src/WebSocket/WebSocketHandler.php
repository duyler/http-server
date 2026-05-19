<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface as TcpConnection;
use Duyler\HttpServer\Processor\RequestProcessorInterface;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use Throwable;

final class WebSocketHandler implements WebSocketHandlerInterface
{
    /** @var array<string, WebSocketServer> */
    private array $wsServers = [];

    /** @var array<int, Connection> */
    private array $wsConnections = [];

    public function __construct(private readonly ServerConfig $config, private readonly RequestProcessorInterface $requestProcessor, private readonly SocketIdGenerator $socketIdGenerator = new SocketIdGenerator(), private LoggerInterface $logger = new NullLogger()) {}

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Override]
    public function attachWebSocketServer(string $path, WebSocketServer $server): void
    {
        $this->wsServers[$path] = $server;
        $server->setLogger($this->logger);
        $this->logger->info('WebSocket attached', ['path' => $path]);
    }

    #[Override]
    public function hasWebSocketServers(): bool
    {
        return count($this->wsServers) > 0;
    }

    public function hasWebSocketConnection(TcpConnection $connection): bool
    {
        $socketId = $this->socketIdGenerator->generate($connection->getSocket());
        return isset($this->wsConnections[$socketId]);
    }

    public function getWebSocketConnection(TcpConnection $connection): ?Connection
    {
        $socketId = $this->socketIdGenerator->generate($connection->getSocket());
        return $this->wsConnections[$socketId] ?? null;
    }

    #[Override]
    public function handleHandshake(TcpConnection $connection, ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $wsServer = $this->wsServers[$path] ?? null;

        if (null === $wsServer) {
            $this->logger->debug('WebSocket endpoint not found', ['path' => $path]);
            $this->requestProcessor->sendErrorResponse($connection, 404, 'WebSocket endpoint not found');
            return false;
        }

        $config = $wsServer->getConfig();

        if (Handshake::isInsecureConfig($config)) {
            $this->logger->warning('WebSocket insecure configuration detected: validateOrigin is disabled with wildcard allowedOrigins', [
                'path' => $path,
            ]);
        }

        if (!Handshake::validateOrigin($request, $config)) {
            $this->logger->warning('WebSocket origin validation failed', [
                'origin' => $request->getHeaderLine('Origin'),
            ]);
            $this->requestProcessor->sendErrorResponse($connection, 403, 'Origin not allowed');
            return false;
        }

        $response = Handshake::createResponse($request, $config);
        $connection->write($response);

        $wsConn = new Connection($connection, $request, $wsServer);
        $wsConn->setState(ConnectionState::OPEN);

        $socketId = $this->socketIdGenerator->generate($connection->getSocket());
        $this->wsConnections[$socketId] = $wsConn;

        $connection->clearBuffer();

        $wsServer->addConnection($wsConn);

        $this->logger->info('WebSocket connection established', [
            'path' => $path,
            'remote' => $connection->getRemoteAddress(),
            'conn_id' => $wsConn->getId(),
        ]);

        return true;
    }

    #[Override]
    public function handleData(TcpConnection $connection): bool
    {
        $socketId = $this->socketIdGenerator->generate($connection->getSocket());
        $wsConn = $this->wsConnections[$socketId] ?? null;

        if (null === $wsConn) {
            return false;
        }

        return $this->processWebSocketData($connection, $wsConn);
    }

    public function handleDataForConnection(TcpConnection $connection, Connection $wsConn): bool
    {
        return $this->processWebSocketData($connection, $wsConn);
    }

    public function processWebSocketDataDirect(TcpConnection $connection, Connection $wsConn): bool
    {
        if (false === $connection->isValid()) {
            $wsConn->close();
            return false;
        }

        try {
            $data = $connection->read($this->config->bufferSize);

            if (false === $data || '' === $data) {
                $wsConn->close();
                return false;
            }

            $connection->appendToBuffer($data);

            if ($connection->isClosed()) {
                return false;
            }

            while (true) {
                $buffer = $connection->getBuffer();
                $frame = Frame::decode($buffer);

                if (null === $frame) {
                    break;
                }

                $frameSize = $frame->getSize();
                $remaining = substr($buffer, $frameSize);

                $connection->clearBuffer();
                if ('' !== $remaining) {
                    $connection->appendToBuffer($remaining);

                    if ($connection->isClosed()) {
                        return false;
                    }
                }

                $message = $wsConn->processFrame($frame);

                if (null !== $message) {
                    $wsConn->getServer()->emit('message', $wsConn, $message);
                }
            }

            return true;
        } catch (Throwable $e) {
            if ($this->config->debugMode) {
                $this->logger->debug('WebSocket read error, closing connection', [
                    'conn_id' => $wsConn->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
            $wsConn->close();
            return false;
        }
    }

    private function processWebSocketData(TcpConnection $connection, Connection $wsConn): bool
    {
        if (false === $connection->isValid()) {
            $wsConn->close();
            return false;
        }

        $socket = $connection->getSocket();
        $internalResource = $socket instanceof StreamSocketResource
            ? $socket->getInternalResource()
            : null;

        if (null === $internalResource) {
            return false;
        }

        if ($internalResource instanceof Socket) {
            $read = [$internalResource];
            $write = null;
            $except = null;
            $changed = socket_select($read, $write, $except, 0);

            if (false === $changed || 0 === $changed) {
                return true;
            }
        } else {
            $read = [$internalResource];
            $write = null;
            $except = null;
            $changed = stream_select($read, $write, $except, 0);

            if (false === $changed || 0 === $changed) {
                return true;
            }
        }

        try {
            $data = $connection->read($this->config->bufferSize);

            if (false === $data || '' === $data) {
                $wsConn->close();
                return false;
            }

            $connection->appendToBuffer($data);

            if ($connection->isClosed()) {
                return false;
            }

            while (true) {
                $buffer = $connection->getBuffer();
                $frame = Frame::decode($buffer);

                if (null === $frame) {
                    break;
                }

                $frameSize = $frame->getSize();
                $remaining = substr($buffer, $frameSize);

                $connection->clearBuffer();
                if ('' !== $remaining) {
                    $connection->appendToBuffer($remaining);

                    if ($connection->isClosed()) {
                        return false;
                    }
                }

                $message = $wsConn->processFrame($frame);

                if (null !== $message) {
                    $wsConn->getServer()->emit('message', $wsConn, $message);
                }
            }

            return true;
        } catch (Throwable $e) {
            if ($this->config->debugMode) {
                $this->logger->debug('WebSocket read error, closing connection', [
                    'conn_id' => $wsConn->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
            $wsConn->close();
            return false;
        }
    }

    #[Override]
    public function processKeepalive(): void
    {
        foreach ($this->wsServers as $wsServer) {
            $wsServer->processPings();
            $wsServer->cleanupClosedConnections();
        }
    }

    #[Override]
    public function closeAll(): void
    {
        foreach ($this->wsServers as $wsServer) {
            $wsServer->closeAll();
        }
        $this->wsConnections = [];
    }

    #[Override]
    public function reset(): void
    {
        foreach ($this->wsServers as $wsServer) {
            $wsServer->closeAll();
        }
        $this->wsConnections = [];
    }

    public function removeConnection(TcpConnection $connection): void
    {
        $socketId = $this->socketIdGenerator->generate($connection->getSocket());
        unset($this->wsConnections[$socketId]);
    }

    /** @return array<string, WebSocketServer> */
    public function getWebSocketServers(): array
    {
        return $this->wsServers;
    }
}
