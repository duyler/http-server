<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Exception\HttpServerException;
use Duyler\HttpServer\Handler\StaticFileHandler;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Socket\SocketInterface;
use Duyler\HttpServer\Socket\SslSocket;
use Duyler\HttpServer\Socket\StreamSocket;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use SplQueue;
use Throwable;

class Server implements ServerInterface
{
    private SocketInterface $socket;
    private ConnectionPool $connectionPool;
    private RequestParser $requestParser;
    private ResponseWriter $responseWriter;
    private HttpParser $httpParser;
    private LoggerInterface $logger;
    private ?StaticFileHandler $staticFileHandler = null;

    /** @var SplQueue<array{request: ServerRequestInterface, connection: Connection}> */
    private SplQueue $requestQueue;

    /** @var array<int, Connection> */
    private array $pendingResponses = [];

    private bool $isRunning = false;

    public function __construct(
        private readonly ServerConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $this->requestParser = new RequestParser($this->httpParser, $psr17Factory);
        $this->responseWriter = new ResponseWriter();
        $this->connectionPool = new ConnectionPool($this->config->maxConnections);
        $this->requestQueue = new SplQueue();
        $this->logger = $logger ?? new NullLogger();

        if ($this->config->publicPath !== null) {
            $this->staticFileHandler = new StaticFileHandler(
                $this->config->publicPath,
                $this->config->enableStaticCache,
                $this->config->staticCacheSize,
            );
        }

        ErrorHandler::register(
            $this->logger,
            /**
             * @param array{type: int, message: string, file: string, line: int} $error
             */
            function (array $error): void {
                $this->handleFatalError($error);
            },
            function (int $signal): void {
                $this->handleSignal($signal);
            },
        );
    }

    public function start(): void
    {
        if ($this->isRunning) {
            $this->logger->warning('Server is already running');
            return;
        }

        try {
            $this->socket = $this->createSocket();
            $this->socket->bind($this->config->host, $this->config->port);
            $this->socket->listen();
            $this->socket->setBlocking(false);

            $this->isRunning = true;
            $this->logger->info('HTTP Server started', [
                'host' => $this->config->host,
                'port' => $this->config->port,
                'ssl' => $this->config->ssl,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to start server', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->connectionPool->closeAll();

        if (isset($this->socket)) {
            $this->socket->close();
        }

        $this->isRunning = false;

        $this->logger->info('HTTP Server stopped');
    }

    public function reset(): void
    {
        $this->logger->warning('Resetting server state');

        $this->connectionPool->closeAll();
        $this->requestQueue = new SplQueue();
        $this->pendingResponses = [];

        if ($this->staticFileHandler !== null) {
            $this->staticFileHandler->clearCache();
        }

        if (isset($this->socket)) {
            try {
                $this->socket->close();
            } catch (Throwable $e) {
                $this->logger->debug('Error closing socket during reset', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->isRunning = false;

        $this->logger->info('Server state reset complete');
    }

    public function restart(): bool
    {
        $this->logger->warning('Attempting server restart');

        try {
            $this->stop();
            $this->reset();
            $this->start();

            $this->logger->info('Server restarted successfully');
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to restart server', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    public function hasRequest(): bool
    {
        if (!$this->isRunning) {
            throw new HttpServerException('Server is not running');
        }

        $this->acceptNewConnections();
        $this->readFromConnections();
        $this->cleanupTimedOutConnections();

        return !$this->requestQueue->isEmpty();
    }

    public function getRequest(): ServerRequestInterface
    {
        if ($this->requestQueue->isEmpty()) {
            $this->logger->warning('getRequest() called but no requests available');
            throw new HttpServerException('No requests available');
        }

        $item = $this->requestQueue->dequeue();
        $connectionId = $this->getSocketId($item['connection']->getSocket());
        $this->pendingResponses[$connectionId] = $item['connection'];

        if ($this->config->debugMode) {
            $this->logger->debug('Request retrieved', [
                'method' => $item['request']->getMethod(),
                'uri' => (string) $item['request']->getUri(),
                'connection_id' => $connectionId,
            ]);
        }

        return $item['request'];
    }

    public function respond(ResponseInterface $response): void
    {
        if (count($this->pendingResponses) === 0) {
            $this->logger->warning('respond() called but no pending responses - ignoring');
            return;
        }

        $connection = array_shift($this->pendingResponses);

        if ($connection === null) {
            $this->logger->warning('respond() called but connection not found - ignoring');
            return;
        }

        if (!$connection->isValid()) {
            if ($this->config->debugMode) {
                $this->logger->debug('respond() called but connection is no longer valid - closing');
            }
            $this->closeConnection($connection);
            return;
        }

        try {
            $this->sendResponse($connection, $response);
        } catch (Throwable $e) {
            $this->logger->error('Failed to send response', [
                'error' => $e->getMessage(),
                'status' => $response->getStatusCode(),
            ]);
            $this->closeConnection($connection);
        }
    }

    public function hasPendingResponse(): bool
    {
        return count($this->pendingResponses) > 0;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStaticCacheStats(): ?array
    {
        return $this->staticFileHandler?->getCacheStats();
    }

    private function createSocket(): SocketInterface
    {
        if ($this->config->ssl) {
            $cert = $this->config->sslCert;
            $key = $this->config->sslKey;

            if ($cert === null || $key === null) {
                throw new HttpServerException('SSL enabled but certificate or key not provided');
            }

            return new SslSocket(
                $cert,
                $key,
                str_contains($this->config->host, ':'),
            );
        }

        return new StreamSocket(
            str_contains($this->config->host, ':'),
        );
    }

    private function acceptNewConnections(): void
    {
        while (true) {
            $clientSocket = $this->socket->accept();

            if ($clientSocket === false) {
                break;
            }

            $remoteAddr = '0.0.0.0';
            $remotePort = 0;

            if (is_resource($clientSocket)) {
                $remoteName = stream_socket_get_name($clientSocket, true);
            } elseif ($clientSocket instanceof Socket) {
                socket_getpeername($clientSocket, $remoteAddr, $remotePort);
                $remoteName = "$remoteAddr:$remotePort";
            }

            if ($remoteName !== false) {
                [$remoteAddr, $remotePort] = explode(':', $remoteName, 2);
                $remotePort = (int) $remotePort;
            }

            $connection = new Connection($clientSocket, $remoteAddr, $remotePort);
            $this->connectionPool->add($connection);

            if ($this->config->debugMode) {
                $this->logger->debug('New connection accepted', [
                    'remote' => "$remoteAddr:$remotePort",
                    'total_connections' => $this->connectionPool->count(),
                ]);
            }
        }
    }

    private function readFromConnections(): void
    {
        $connections = $this->connectionPool->getAll();

        if (count($connections) === 0) {
            return;
        }

        foreach ($connections as $connection) {
            if (!$connection->isValid()) {
                $this->closeConnection($connection);
                continue;
            }

            $socket = $connection->getSocket();

            if (!is_resource($socket) && !$socket instanceof Socket) {
                $this->closeConnection($connection);
                continue;
            }

            if ($socket instanceof Socket) {
                $read = [$socket];
                $write = null;
                $except = null;
                $changed = @socket_select($read, $write, $except, 0);

                if ($changed === false || $changed === 0) {
                    continue;
                }
            } elseif (is_resource($socket)) {
                $read = [$socket];
                $write = null;
                $except = null;
                $changed = @stream_select($read, $write, $except, 0);

                if ($changed === false || $changed === 0) {
                    continue;
                }
            }

            $data = $connection->read($this->config->bufferSize);

            if ($data === false || $data === '') {
                $this->closeConnection($connection);
                continue;
            }

            $connection->appendToBuffer($data);

            if ($this->httpParser->hasCompleteHeaders($connection->getBuffer())) {
                $this->processRequest($connection);
            }
        }
    }

    private function processRequest(Connection $connection): void
    {
        try {
            $buffer = $connection->getBuffer();

            $connection->startRequestTimer();

            if ($connection->isRequestTimedOut($this->config->requestTimeout)) {
                $this->logger->warning('Request reading timeout', [
                    'remote' => $connection->getRemoteAddress(),
                    'timeout' => $this->config->requestTimeout,
                ]);
                $this->sendErrorResponse($connection, 408, 'Request Timeout');
                return;
            }

            [$headerBlock, $body] = $this->httpParser->splitHeadersAndBody($buffer);

            if ($connection->getCachedHeaders() === null) {
                $lines = explode("\r\n", $headerBlock);
                $headerText = implode("\r\n", array_slice($lines, 1));
                $headers = $this->httpParser->parseHeaders($headerText);
                $contentLength = $this->httpParser->getContentLength($headers);

                $connection->setCachedHeaders($headers);
                $connection->setExpectedContentLength($contentLength);
            } else {
                $headers = $connection->getCachedHeaders();
                $contentLength = $connection->getExpectedContentLength();
            }

            if (strlen($body) < $contentLength) {
                return;
            }

            if ($contentLength > $this->config->maxRequestSize) {
                $this->logger->warning('Request payload too large', [
                    'content_length' => $contentLength,
                    'max_allowed' => $this->config->maxRequestSize,
                ]);
                $this->sendErrorResponse($connection, 413, 'Payload Too Large');
                return;
            }

            $request = $this->requestParser->parse(
                $buffer,
                $connection->getRemoteAddress(),
                $connection->getRemotePort(),
            );

            if ($this->staticFileHandler !== null && $this->staticFileHandler->isStaticFile($request)) {
                $connection->incrementRequestCount();

                $connectionHeader = $request->getHeaderLine('Connection');
                $keepAlive = $this->config->enableKeepAlive
                    && (strcasecmp($connectionHeader, 'close') !== 0)
                    && $connection->getRequestCount() < $this->config->keepAliveMaxRequests;

                $connection->setKeepAlive($keepAlive);

                $response = $this->staticFileHandler->handle($request);

                if ($response !== null) {
                    $this->sendResponse($connection, $response);
                }

                $connection->clearBuffer();

                return;
            }

            $this->requestQueue->enqueue([
                'request' => $request,
                'connection' => $connection,
            ]);

            $connection->clearBuffer();
            $connection->incrementRequestCount();

            $connectionHeader = $request->getHeaderLine('Connection');
            $keepAlive = $this->config->enableKeepAlive
                && strcasecmp($connectionHeader, 'keep-alive') === 0
                && $connection->getRequestCount() < $this->config->keepAliveMaxRequests;

            $connection->setKeepAlive($keepAlive);

        } catch (Throwable $e) {
            $this->logger->error('Failed to process request', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'remote' => $connection->getRemoteAddress() . ':' . $connection->getRemotePort(),
            ]);
            $this->sendErrorResponse($connection, 400, 'Bad Request');
        }
    }

    private function sendResponse(Connection $connection, ResponseInterface $response): void
    {
        if (!$connection->isValid()) {
            $this->closeConnection($connection);
            return;
        }

        if (!$response->hasHeader('Content-Length')) {
            $body = $response->getBody();
            $size = $body->getSize();

            if ($size !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            } else {
                $bodyContents = (string) $body;
                $size = strlen($bodyContents);
                $response = $response->withHeader('Content-Length', (string) $size);

                $newBody = \Nyholm\Psr7\Stream::create($bodyContents);
                $response = $response->withBody($newBody);
            }
        }

        if (!$connection->isKeepAlive()) {
            $response = $response->withHeader('Connection', 'close');
        } else {
            $response = $response->withHeader('Connection', 'keep-alive')
                ->withHeader('Keep-Alive', sprintf(
                    'timeout=%d, max=%d',
                    $this->config->keepAliveTimeout,
                    $this->config->keepAliveMaxRequests - $connection->getRequestCount(),
                ));
        }

        $httpResponse = $this->responseWriter->write($response);
        $written = $connection->write($httpResponse);

        if ($written === false) {
            $this->logger->warning('Failed to write response', [
                'remote' => $connection->getRemoteAddress(),
            ]);
            $this->closeConnection($connection);
            return;
        }

        if (!$connection->isKeepAlive()) {
            $this->closeConnection($connection);
        }
    }

    private function sendErrorResponse(Connection $connection, int $statusCode, string $message): void
    {
        $response = (new \Nyholm\Psr7\Response($statusCode))
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Connection', 'close');

        $response->getBody()->write($message);

        $httpResponse = $this->responseWriter->write($response);
        $connection->write($httpResponse);

        $this->closeConnection($connection);
    }

    private function closeConnection(Connection $connection): void
    {
        if ($this->config->debugMode) {
            $this->logger->debug('Closing connection', [
                'remote' => $connection->getRemoteAddress(),
                'requests_handled' => $connection->getRequestCount(),
            ]);
        }

        $connection->close();
        $this->connectionPool->remove($connection);
    }

    private function cleanupTimedOutConnections(): void
    {
        $this->connectionPool->removeTimedOut($this->config->connectionTimeout);
    }

    /**
     * @param resource|Socket $socket
     */
    private function getSocketId(mixed $socket): int
    {
        if ($socket instanceof Socket) {
            return spl_object_id($socket);
        }

        if (is_resource($socket)) {
            return get_resource_id($socket);
        }

        return 0;
    }

    /**
     * @param array{type: int, message: string, file: string, line: int} $error
     */
    private function handleFatalError(array $error): void
    {
        $this->logger->emergency('Fatal error occurred, attempting recovery', [
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        try {
            $this->reset();
            $this->logger->warning('Server state cleared after fatal error, ready for restart');
        } catch (Throwable $e) {
            $this->logger->critical('Failed to reset server after fatal error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleSignal(int $signal): void
    {
        $this->logger->info('Signal received, stopping server gracefully', [
            'signal' => $signal,
        ]);

        try {
            $this->stop();
            $this->logger->info('Server stopped gracefully');
        } catch (Throwable $e) {
            $this->logger->error('Error during graceful shutdown', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
