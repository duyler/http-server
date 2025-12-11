<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Exception\HttpServerException;
use Duyler\HttpServer\Handler\StaticFileHandler;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\RateLimit\RateLimiter;
use Duyler\HttpServer\Socket\SocketInterface;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Duyler\HttpServer\Socket\SslSocket;
use Duyler\HttpServer\Socket\StreamSocket;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\Upload\TempFileManager;
use Duyler\HttpServer\WebSocket\Connection as WebSocketConnection;
use Duyler\HttpServer\WebSocket\Frame;
use Duyler\HttpServer\WebSocket\Handshake;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Fiber;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use SplQueue;
use Throwable;

class Server implements ServerInterface
{
    private ?SocketInterface $socket = null;
    private readonly ConnectionPool $connectionPool;
    private readonly RequestParser $requestParser;
    private readonly ResponseWriter $responseWriter;
    private readonly HttpParser $httpParser;
    private readonly TempFileManager $tempFileManager;
    private ?StaticFileHandler $staticFileHandler = null;
    private ?RateLimiter $rateLimiter = null;
    private readonly ServerMetrics $metrics;

    /** @var SplQueue<array{request: ServerRequestInterface, connection: Connection}> */
    private SplQueue $requestQueue;

    /** @var array<int, Connection> */
    private array $pendingResponses = [];

    private bool $isRunning = false;
    private bool $isShuttingDown = false;

    private bool $hasWebSocket = false;

    private ServerMode $mode = ServerMode::Standalone;

    private ?int $workerId = null;
    private ?int $workerPid = null;

    /** @var array<Fiber> */
    private array $fibers = [];

    /** @var array<string, WebSocketServer> */
    private array $wsServers = [];

    /** @var array<int, WebSocketConnection> */
    private array $wsConnections = [];

    public function __construct(
        private readonly ServerConfig $config,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $this->tempFileManager = new TempFileManager();
        $this->requestParser = new RequestParser($this->httpParser, $psr17Factory, $this->tempFileManager);
        $this->responseWriter = new ResponseWriter();
        $this->connectionPool = new ConnectionPool($this->config->maxConnections);
        /** @psalm-suppress MixedPropertyTypeCoercion */
        $this->requestQueue = new SplQueue();
        $this->metrics = new ServerMetrics();

        if ($this->config->publicPath !== null) {
            $this->staticFileHandler = new StaticFileHandler(
                $this->config->publicPath,
                $this->config->enableStaticCache,
                $this->config->staticCacheSize,
            );
        }

        if ($this->config->enableRateLimit) {
            $this->rateLimiter = new RateLimiter(
                $this->config->rateLimitRequests,
                $this->config->rateLimitWindow,
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

    #[Override]
    public function start(): bool
    {
        if ($this->mode === ServerMode::WorkerPool) {
            $this->logger->warning('start() should not be called in Worker Pool mode', [
                'worker_id' => $this->workerId,
            ]);
            return $this->isRunning;
        }

        if ($this->isRunning) {
            $this->logger->warning('Server is already running');
            return true;
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
            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to start server', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    #[Override]
    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        if ($this->hasWebSocket) {
            foreach ($this->wsServers as $wsServer) {
                $wsServer->closeAll();
            }
        }

        $this->connectionPool->closeAll();

        if (isset($this->socket)) {
            $this->socket->close();
        }

        $this->isRunning = false;
        $this->isShuttingDown = false;

        $this->logger->info('HTTP Server stopped');
    }

    #[Override]
    public function shutdown(int $timeout = 30): bool
    {
        if (!$this->isRunning) {
            $this->logger->warning('Cannot shutdown: server is not running');
            return true;
        }

        if ($this->isShuttingDown) {
            $this->logger->warning('Server is already shutting down');
            return false;
        }

        $this->isShuttingDown = true;
        $this->logger->info('Graceful shutdown initiated', ['timeout' => $timeout]);

        $startTime = time();
        $activeCount = $this->getActiveConnectionCount();

        while (($activeCount > 0 || !$this->requestQueue->isEmpty() || count($this->pendingResponses) > 0)
               && (time() - $startTime) < $timeout) {
            usleep(Constants::SHUTDOWN_POLL_INTERVAL_MICROSECONDS);

            try {
                $this->readFromConnections();
                $this->cleanupTimedOutConnections();
            } catch (Throwable $e) {
                $this->logger->debug('Error during shutdown processing', [
                    'error' => $e->getMessage(),
                ]);
            }

            $activeCount = $this->getActiveConnectionCount();

            if ($this->config->debugMode && $activeCount > 0) {
                $this->logger->debug('Waiting for connections to finish', [
                    'active' => $activeCount,
                    'pending_responses' => count($this->pendingResponses),
                    'queued_requests' => $this->requestQueue->count(),
                    'elapsed' => time() - $startTime,
                ]);
            }
        }

        $elapsed = time() - $startTime;
        $graceful = $activeCount === 0 && $this->requestQueue->isEmpty() && count($this->pendingResponses) === 0;

        if ($graceful) {
            $this->logger->info('Graceful shutdown completed successfully', [
                'elapsed' => $elapsed,
            ]);
        } else {
            $this->logger->warning('Graceful shutdown timeout reached, forcing shutdown', [
                'remaining_active' => $activeCount,
                'remaining_pending' => count($this->pendingResponses),
                'remaining_queued' => $this->requestQueue->count(),
                'elapsed' => $elapsed,
            ]);
        }

        $this->stop();

        return $graceful;
    }

    #[Override]
    public function reset(): void
    {
        $this->logger->warning('Resetting server state');

        if ($this->hasWebSocket) {
            foreach ($this->wsServers as $wsServer) {
                $wsServer->closeAll();
            }
            $this->wsConnections = [];
        }

        $this->connectionPool->closeAll();
        /** @psalm-suppress MixedPropertyTypeCoercion */
        $this->requestQueue = new SplQueue();
        $this->pendingResponses = [];
        $this->tempFileManager->cleanup();

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

    #[Override]
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

    #[Override]
    public function hasRequest(): bool
    {
        try {
            // Resume all registered Fibers before processing
            // This is used in Event-Driven Worker Pool mode to accept
            // connections from Master in background
            foreach ($this->fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    try {
                        $fiber->resume();
                    } catch (Throwable $e) {
                        $this->logger->error('Error resuming Fiber', [
                            'error' => $e->getMessage(),
                            'worker_id' => $this->workerId,
                        ]);
                    }
                }
            }

            if (!$this->isRunning) {
                $this->logger->warning('hasRequest() called but server is not running');
                return false;
            }

            // Only accept new connections in Standalone mode
            // In Worker Pool mode, connections come via addExternalConnection()
            if (!$this->isShuttingDown && $this->mode === ServerMode::Standalone) {
                $this->acceptNewConnections();
            }

            $this->readFromConnections();
            $this->cleanupTimedOutConnections();

            if ($this->hasWebSocket) {
                $this->processWebSocketKeepalive();
            }

            return !$this->requestQueue->isEmpty();
        } catch (Throwable $e) {
            $this->logger->error('Error in hasRequest()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    #[Override]
    public function getRequest(): ?ServerRequestInterface
    {
        try {
            if ($this->requestQueue->isEmpty()) {
                $this->logger->warning('getRequest() called but no requests available');
                return null;
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
        } catch (Throwable $e) {
            $this->logger->error('Error in getRequest()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    #[Override]
    public function respond(ResponseInterface $response): void
    {
        if (count($this->pendingResponses) === 0) {
            $this->logger->warning('respond() called but no pending responses - ignoring');
            return;
        }

        $connection = array_shift($this->pendingResponses);

        if (!$connection->isValid()) {
            if ($this->config->debugMode) {
                $this->logger->debug('respond() called but connection is no longer valid - closing');
            }
            $this->closeConnection($connection);
            return;
        }

        try {
            $this->sendResponse($connection, $response);
            if ($response->getStatusCode() < 400) {
                $this->metrics->incrementSuccessfulRequests();
            } else {
                $this->metrics->incrementFailedRequests();
            }
        } catch (Throwable $e) {
            $this->metrics->incrementFailedRequests();
            $this->logger->error('Failed to send response', [
                'error' => $e->getMessage(),
                'status' => $response->getStatusCode(),
            ]);
            $this->closeConnection($connection);
        }
    }

    #[Override]
    public function hasPendingResponse(): bool
    {
        return count($this->pendingResponses) > 0;
    }

    #[Override]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return array<string, int|float|string>
     */
    #[Override]
    public function getMetrics(): array
    {
        $this->metrics->setActiveConnections($this->connectionPool->count());
        return $this->metrics->getMetrics();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStaticCacheStats(): ?array
    {
        return $this->staticFileHandler?->getCacheStats();
    }

    #[Override]
    public function attachWebSocket(string $path, WebSocketServer $ws): void
    {
        $this->wsServers[$path] = $ws;
        $this->hasWebSocket = true;
        $ws->setLogger($this->logger);

        $this->logger->info('WebSocket attached', ['path' => $path]);
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
        $acceptedCount = 0;

        while ($acceptedCount < $this->config->maxAcceptsPerCycle) {
            assert($this->socket !== null);
            $clientSocketResource = $this->socket->accept();

            if ($clientSocketResource === false) {
                break;
            }

            $acceptedCount++;

            $remoteAddr = '0.0.0.0';
            $remotePort = 0;

            $internalResource = $clientSocketResource instanceof StreamSocketResource
                ? $clientSocketResource->getInternalResource()
                : null;

            if ($internalResource !== null) {
                if ($internalResource instanceof Socket) {
                    socket_getpeername($internalResource, $remoteAddr, $remotePort);
                } else {
                    $remoteName = stream_socket_get_name($internalResource, true);
                    if ($remoteName !== false) {
                        $parts = explode(':', $remoteName, 2);
                        $remoteAddr = $parts[0];
                        $remotePort = isset($parts[1]) ? (int) $parts[1] : 0;
                    }
                }
            }

            $connection = new Connection($clientSocketResource, $remoteAddr, $remotePort);
            $this->connectionPool->add($connection);
            $this->metrics->incrementTotalConnections();

            if ($this->config->debugMode) {
                $this->logger->debug('New connection accepted', [
                    'remote' => "$remoteAddr:$remotePort",
                    'total_connections' => $this->connectionPool->count(),
                    'accepts_this_cycle' => $acceptedCount,
                ]);
            }
        }

        if ($acceptedCount >= $this->config->maxAcceptsPerCycle && $this->config->debugMode) {
            $this->logger->debug('Max accepts per cycle reached', [
                'limit' => $this->config->maxAcceptsPerCycle,
                'note' => 'Deferring remaining connections to next cycle',
            ]);
        }
    }

    private function readFromConnections(): void
    {
        $connections = $this->connectionPool->getAll();

        if (count($connections) === 0) {
            return;
        }

        foreach ($connections as $connection) {
            if ($this->hasWebSocket) {
                $connId = $this->getSocketId($connection->getSocket());

                if (isset($this->wsConnections[$connId])) {
                    $this->handleWebSocketData($connection, $this->wsConnections[$connId]);
                    continue;
                }
            }

            if (!$connection->isValid()) {
                $this->closeConnection($connection);
                continue;
            }

            $socket = $connection->getSocket();
            $internalResource = $socket instanceof StreamSocketResource
                ? $socket->getInternalResource()
                : null;

            if ($internalResource === null) {
                $this->closeConnection($connection);
                continue;
            }

            if ($internalResource instanceof Socket) {
                $read = [$internalResource];
                $write = null;
                $except = null;
                $changed = socket_select($read, $write, $except, 0);

                if ($changed === false || $changed === 0) {
                    continue;
                }
            } else {
                $read = [$internalResource];
                $write = null;
                $except = null;
                $changed = stream_select($read, $write, $except, 0);

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

            if ($this->hasWebSocket && Handshake::isWebSocketRequest($request)) {
                $this->handleWebSocketHandshake($connection, $request);
                return;
            }

            if ($this->rateLimiter !== null && !$this->rateLimiter->isAllowed($connection->getRemoteAddress())) {
                $this->logger->warning('Rate limit exceeded', [
                    'remote' => $connection->getRemoteAddress(),
                ]);

                $resetTime = $this->rateLimiter->getResetTime($connection->getRemoteAddress());
                $response = new Response(429, [
                    'Content-Type' => 'text/plain',
                    'Retry-After' => (string) $resetTime,
                    'X-RateLimit-Limit' => (string) $this->config->rateLimitRequests,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) (time() + $resetTime),
                ], 'Too Many Requests');

                $this->sendResponse($connection, $response);
                return;
            }

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

            $this->metrics->incrementRequests();
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
                'error_class' => $e::class,
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
        $this->metrics->incrementClosedConnections();
    }

    private function cleanupTimedOutConnections(): void
    {
        $removed = $this->connectionPool->removeTimedOut($this->config->connectionTimeout);
        for ($i = 0; $i < $removed; $i++) {
            $this->metrics->incrementTimedOutConnections();
        }
    }


    private function getActiveConnectionCount(): int
    {
        return $this->connectionPool->count();
    }

    private function handleWebSocketHandshake(Connection $connection, ServerRequestInterface $request): void
    {
        $path = $request->getUri()->getPath();

        $wsServer = $this->wsServers[$path] ?? null;

        if ($wsServer === null) {
            $this->logger->debug('WebSocket endpoint not found', ['path' => $path]);
            $this->sendErrorResponse($connection, 404, 'WebSocket endpoint not found');
            return;
        }

        $config = $wsServer->getConfig();
        if (!Handshake::validateOrigin($request, $config)) {
            $this->logger->warning('WebSocket origin validation failed', [
                'origin' => $request->getHeaderLine('Origin'),
            ]);
            $this->sendErrorResponse($connection, 403, 'Origin not allowed');
            return;
        }

        $response = Handshake::createResponse($request, $config);
        $connection->write($response);

        $wsConn = new WebSocketConnection($connection, $request, $wsServer);
        $wsConn->setState(\Duyler\HttpServer\WebSocket\Enum\ConnectionState::OPEN);

        $connId = $this->getSocketId($connection->getSocket());
        $this->wsConnections[$connId] = $wsConn;

        $connection->clearBuffer();

        $wsServer->addConnection($wsConn);

        $this->logger->info('WebSocket connection established', [
            'path' => $path,
            'remote' => $connection->getRemoteAddress(),
            'conn_id' => $wsConn->getId(),
        ]);
    }

    private function handleWebSocketData(Connection $tcpConn, WebSocketConnection $wsConn): void
    {
        if (!$tcpConn->isValid()) {
            $wsConn->close();
            return;
        }

        $socket = $tcpConn->getSocket();
        $internalResource = $socket instanceof StreamSocketResource
            ? $socket->getInternalResource()
            : null;

        if ($internalResource === null) {
            return;
        }

        if ($internalResource instanceof Socket) {
            $read = [$internalResource];
            $write = null;
            $except = null;
            $changed = socket_select($read, $write, $except, 0);

            if ($changed === false || $changed === 0) {
                return;
            }
        } else {
            $read = [$internalResource];
            $write = null;
            $except = null;
            $changed = stream_select($read, $write, $except, 0);

            if ($changed === false || $changed === 0) {
                return;
            }
        }

        try {
            $data = $tcpConn->read($this->config->bufferSize);

            if ($data === false || $data === '') {
                $wsConn->close();
                return;
            }

            $tcpConn->appendToBuffer($data);

            while (true) {
                $buffer = $tcpConn->getBuffer();
                $frame = Frame::decode($buffer);

                if ($frame === null) {
                    break;
                }

                $frameSize = $frame->getSize();
                $remaining = substr($buffer, $frameSize);

                $tcpConn->clearBuffer();
                if ($remaining !== '') {
                    $tcpConn->appendToBuffer($remaining);
                }

                $message = $wsConn->processFrame($frame);

                if ($message !== null) {
                    $wsConn->getServer()->emit('message', $wsConn, $message);
                }
            }
        } catch (Throwable $e) {
            if ($this->config->debugMode) {
                $this->logger->debug('WebSocket read error, closing connection', [
                    'conn_id' => $wsConn->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
            $wsConn->close();
        }
    }

    private function processWebSocketKeepalive(): void
    {
        foreach ($this->wsServers as $wsServer) {
            $wsServer->processPings();
            $wsServer->cleanupClosedConnections();
        }
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

    /**
     * Add external connection from Worker Pool Master
     *
     * @param array{client_ip?: string, worker_id: int, worker_pid?: int} $metadata
     */
    #[Override]
    public function addExternalConnection(Socket $clientSocket, array $metadata): void
    {
        if (!isset($metadata['worker_id'])) {
            throw new HttpServerException('worker_id is required in metadata for addExternalConnection()');
        }

        $workerContext = ['worker_id' => $metadata['worker_id']];
        if (isset($metadata['worker_pid'])) {
            $workerContext['worker_pid'] = $metadata['worker_pid'];
        }
        $this->setWorkerContext($workerContext);

        $clientIp = $metadata['client_ip'] ?? '0.0.0.0';
        $clientPort = 0;

        if (socket_getpeername($clientSocket, $clientIp, $clientPort) === false) {
            $clientIp = $metadata['client_ip'] ?? '0.0.0.0';
            $clientPort = 0;

            $this->logger->warning('Failed to get peer name', [
                'error' => socket_strerror(socket_last_error($clientSocket)),
                'fallback_ip' => $clientIp,
            ]);
        }

        $socketResource = new StreamSocketResource($clientSocket);
        $connection = new Connection($socketResource, $clientIp, $clientPort);

        $this->connectionPool->add($connection);

        $this->logger->debug('External connection added', [
            'client_ip' => $clientIp,
            'client_port' => $clientPort,
            'worker_id' => $this->workerId,
        ]);
    }

    /**
     * @param array{worker_id: int, worker_pid?: int} $context
     */
    private function setWorkerContext(array $context): void
    {
        if ($this->mode === ServerMode::WorkerPool) {
            return;
        }

        $this->mode = ServerMode::WorkerPool;
        $this->workerId = $context['worker_id'];
        $this->workerPid = $context['worker_pid'] ?? null;

        $this->logger->info('Worker context set', [
            'worker_id' => $this->workerId,
            'worker_pid' => $this->workerPid,
            'mode' => $this->mode->value,
        ]);
    }

    #[Override]
    public function getMode(): ServerMode
    {
        return $this->mode;
    }

    #[Override]
    public function getWorkerId(): ?int
    {
        return $this->workerId;
    }

    #[Override]
    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
        $this->mode = ServerMode::WorkerPool;
        $this->isRunning = true; // Mark as running in Worker Pool mode

        $this->logger->info('Worker ID set', [
            'worker_id' => $workerId,
            'mode' => $this->mode->value,
        ]);
    }

    /**
     * @param Fiber $fiber
     */
    #[Override]
    public function registerFiber(Fiber $fiber): void
    {
        $this->fibers[] = $fiber;

        $this->logger->debug('Fiber registered', [
            'total_fibers' => count($this->fibers),
            'worker_id' => $this->workerId,
        ]);
    }

    private function getSocketId(SocketResourceInterface $socket): int
    {
        return spl_object_id($socket);
    }
}
