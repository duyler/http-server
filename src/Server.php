<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Config\ServerMode;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Connection\ConnectionManager;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\ErrorHandler\ErrorHandler;
use Duyler\HttpServer\ErrorHandler\ErrorHandlerInterface;
use Duyler\HttpServer\Exception\InvalidConfigException;
use Duyler\HttpServer\Exception\MemoryLimitExceededException;
use Duyler\HttpServer\Exception\ServerException;
use Duyler\HttpServer\Handler\StaticFileHandler;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Notification\EventLoopNotifier;
use Duyler\HttpServer\Notification\NotificationManager;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Processor\RequestQueue;
use Duyler\HttpServer\Processor\ResponseSender;
use Duyler\HttpServer\Processor\WebSocketUpgradeHandler;
use Duyler\HttpServer\RateLimit\RateLimiter;
use Duyler\HttpServer\Security\AuditLogger;
use Duyler\HttpServer\Security\CorsService;
use Duyler\HttpServer\Security\SecurityHeadersService;
use Duyler\HttpServer\Socket\ExistingSocket;
use Duyler\HttpServer\Socket\SocketInterface;
use Duyler\HttpServer\Socket\SocketNotificationPair;
use Duyler\HttpServer\Socket\SocketResourceInterface;
use Duyler\HttpServer\Socket\SslSocket;
use Duyler\HttpServer\Socket\StreamSocket;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\Upload\TempFileManager;
use Duyler\HttpServer\Util\ClientIpResolver;
use Duyler\HttpServer\WebSocket\Handshake;
use Duyler\HttpServer\WebSocket\WebSocketHandler;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Ev;
use EvIo;
use Fiber;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use Throwable;

final class Server implements ServerInterface
{
    private ?SocketInterface $socket = null;
    private readonly ConnectionPool $connectionPool;
    private readonly ConnectionManager $connectionManager;
    private readonly RequestParser $requestParser;
    private readonly ResponseWriter $responseWriter;
    private readonly HttpParser $httpParser;
    private readonly TempFileManager $tempFileManager;
    private readonly WebSocketHandler $webSocketHandler;
    private readonly NotificationManager $notificationManager;
    private readonly ServerMetrics $metrics;
    private readonly HttpRequestProcessor $requestProcessor;
    private readonly ErrorHandlerInterface $errorHandler;
    private readonly MemoryMonitor $memoryMonitor;
    private ?StaticFileHandler $staticFileHandler = null;
    private ?RateLimiter $rateLimiter = null;
    private ?CorsService $corsService = null;

    private bool $isRunning = false;
    private bool $isShuttingDown = false;
    private bool $hasWebSocket = false;
    private ServerMode $mode = ServerMode::Standalone;
    private ?int $workerId = null;
    private ?int $workerPid = null;
    private mixed $externalSocketResource = null;
    private bool $eventLoopActive = false;
    private int $lastMemoryWarningTime = 0;

    /** @var array<Fiber> */
    private array $fibers = [];

    /** @var array<int, EvIo> Client socket watchers, key = connection ID */
    private array $clientWatchers = [];

    /** @var EvIo|null Watcher for listening socket */
    private ?EvIo $listeningWatcher = null;

    private bool $watchersStarted = false;

    /** @var resource|null Cached notification stream */
    private $notificationStream = null;

    public function __construct(
        private readonly ServerConfig $config,
        private LoggerInterface $logger = new NullLogger(),
        ?ErrorHandlerInterface $errorHandler = null,
    ) {
        $this->httpParser = new HttpParser($this->config->headerCacheLimit);
        $psr17Factory = new Psr17Factory();
        $this->tempFileManager = new TempFileManager();
        $this->requestParser = new RequestParser($this->httpParser, $psr17Factory, $this->tempFileManager);
        $this->responseWriter = new ResponseWriter();

        if ($this->config->enableSecurityHeaders) {
            $securityHeadersService = new SecurityHeadersService(
                enableXContentTypeOptions: true,
                enableXFrameOptions: true,
                enableXXSSProtection: true,
                enableReferrerPolicy: true,
                enablePermissionsPolicy: true,
                enableHsts: $this->config->enableHsts || $this->config->ssl || 443 === $this->config->port,
                frameOptions: $this->config->frameOptions,
                referrerPolicy: $this->config->referrerPolicy,
                permissionsPolicy: $this->config->permissionsPolicy,
                contentSecurityPolicy: $this->config->contentSecurityPolicy,
                contentSecurityPolicyReportOnly: $this->config->contentSecurityPolicyReportOnly,
                enableNonce: $this->config->enableCspNonce,
                hstsMaxAge: $this->config->hstsMaxAge,
                hstsIncludeSubDomains: $this->config->hstsIncludeSubDomains,
                hstsPreload: $this->config->hstsPreload,
            );
            $this->responseWriter->setSecurityHeadersService($securityHeadersService);
        }

        $this->connectionPool = new ConnectionPool($this->config->maxConnections);
        $this->metrics = new ServerMetrics();
        $this->notificationManager = new NotificationManager(
            new SocketNotificationPair($this->logger),
            $this->logger,
        );

        if (null !== $this->config->publicPath) {
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

        if ($this->config->enableCors) {
            $this->corsService = new CorsService(
                allowedOrigins: $this->config->corsAllowedOrigins,
                allowedMethods: $this->config->corsAllowedMethods,
                allowedHeaders: $this->config->corsAllowedHeaders,
                allowCredentials: $this->config->corsAllowCredentials,
                maxAge: $this->config->corsMaxAge,
                exposeHeaders: $this->config->corsExposeHeaders,
            );
        }

        $this->requestProcessor = new HttpRequestProcessor(
            $this->config,
            $this->httpParser,
            $this->requestParser,
            $this->responseWriter,
            $this->connectionPool,
            $this->metrics,
            $this->tempFileManager,
            new RequestQueue(),
            new ResponseSender($this->config, $this->responseWriter, $this->logger),
            $this->staticFileHandler,
            $this->rateLimiter,
            $this->logger,
        );

        $this->webSocketHandler = new WebSocketHandler(
            $this->config,
            $this->requestProcessor,
            logger: $this->logger,
        );

        if (null !== $this->corsService) {
            $this->requestProcessor->setCorsService($this->corsService);
        }

        $this->connectionManager = new ConnectionManager(
            $this->connectionPool,
            $this->httpParser,
            $this->requestProcessor,
            $this->metrics,
            $this->config,
            $this->logger,
        );

        $this->requestProcessor->setConnectionManager($this->connectionManager);

        $this->memoryMonitor = new MemoryMonitor($this->config->memoryLimit);

        $this->requestProcessor->setWebSocketUpgradeHandler(
            new WebSocketUpgradeHandler(
                function (ConnectionInterface $connection, ServerRequestInterface $request): void {
                    if ($this->hasWebSocket && Handshake::isWebSocketRequest($request)) {
                        $this->webSocketHandler->handleHandshake($connection, $request);
                    }
                },
            ),
        );

        $this->requestProcessor->setEventLoopNotifier(
            new EventLoopNotifier(
                function (): void {
                    $this->notifyEventLoop();
                },
            ),
        );

        $this->errorHandler = $errorHandler ?? new ErrorHandler(
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

        $this->errorHandler->register();
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
            $this->socket->listen($this->config->socketBacklog);
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
        if (false === $this->isRunning) {
            return;
        }

        if ($this->hasWebSocket) {
            $this->webSocketHandler->closeAll();
        }

        $this->connectionPool->closeAll();

        if (isset($this->socket)) {
            $this->socket->close();
        }

        $this->stopWatchers();
        $this->notificationStream = null;

        $this->notificationManager->disable();

        $this->isRunning = false;
        $this->isShuttingDown = false;

        $this->logger->info('HTTP Server stopped');
    }

    #[Override]
    public function shutdown(int $timeout = 30): bool
    {
        if (false === $this->isRunning) {
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

        while (($activeCount > 0 || $this->requestProcessor->hasRequest() || $this->requestProcessor->hasPendingResponse())
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
                    'pending_responses' => $this->requestProcessor->getPendingRequestCount(),
                    'queued_requests' => $this->requestProcessor->getQueueCount(),
                    'elapsed' => time() - $startTime,
                ]);
            }
        }

        $elapsed = time() - $startTime;
        $graceful = $activeCount === 0 && false === $this->requestProcessor->hasRequest() && false === $this->requestProcessor->hasPendingResponse();

        if ($graceful) {
            $this->logger->info('Graceful shutdown completed successfully', [
                'elapsed' => $elapsed,
            ]);
        } else {
            $this->logger->warning('Graceful shutdown timeout reached, forcing shutdown', [
                'remaining_active' => $activeCount,
                'remaining_pending' => $this->requestProcessor->getPendingRequestCount(),
                'remaining_queued' => $this->requestProcessor->getQueueCount(),
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

        $this->errorHandler->reset();

        if ($this->hasWebSocket) {
            $this->webSocketHandler->reset();
        }

        $this->connectionPool->closeAll();
        $this->requestProcessor->reset();

        if (null !== $this->staticFileHandler) {
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

        $this->stopWatchers();
        $this->notificationStream = null;

        $this->notificationManager->disable();

        $this->isRunning = false;
        $this->fibers = [];
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
    public function enableNotification(): void
    {
        $this->notificationManager->enable();

        $this->logger->info('Notification sockets enabled', [
            'worker_id' => $this->workerId,
        ]);
    }

    #[Override]
    public function disableNotification(): void
    {
        $this->notificationStream = null;
        $this->notificationManager->disable();
    }

    #[Override]
    public function hasRequest(): bool
    {
        if ($this->watchersStarted) {
            return $this->requestProcessor->hasRequest();
        }

        try {
            foreach ($this->fibers as $key => $fiber) {
                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                    continue;
                }

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

            if (false === $this->isRunning) {
                $this->logger->warning('hasRequest() called but server is not running');
                return false;
            }

            if (false === $this->isShuttingDown && $this->mode === ServerMode::Standalone) {
                $this->acceptNewConnections();
            }

            $this->readFromConnections();
            $this->cleanupTimedOutConnections();
            $this->requestProcessor->cleanupStaleRequests($this->config->requestTimeout);
            $this->checkMemoryLimit();

            if ($this->hasWebSocket) {
                $this->webSocketHandler->processKeepalive();
            }

            return $this->requestProcessor->hasRequest();
        } catch (MemoryLimitExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('Error in hasRequest()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    #[Override]
    public function getRequest(): ?RequestData
    {
        try {
            $requestData = $this->requestProcessor->getRequest();

            if (null === $requestData) {
                $this->logger->warning('getRequest() called but no requests available');
                return null;
            }

            if ($this->config->debugMode) {
                $this->logger->debug('Request data retrieved', [
                    'request_id' => $requestData->id,
                    'method' => $requestData->request->getMethod(),
                    'uri' => (string) $requestData->request->getUri(),
                    'connection_id' => $requestData->connectionId,
                ]);
            }

            return $requestData;
        } catch (Throwable $e) {
            $this->logger->error('Error in getRequest()', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    #[Override]
    public function respond(ResponseData $responseData): void
    {
        $this->requestProcessor->respond($responseData);
    }

    #[Override]
    public function hasPendingResponse(): bool
    {
        return $this->requestProcessor->hasPendingResponse();
    }

    #[Override]
    public function getPendingRequestId(): ?string
    {
        return $this->requestProcessor->getPendingRequestId();
    }

    #[Override]
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->requestProcessor->setLogger($logger);
        $this->connectionManager->setLogger($logger);
        $this->webSocketHandler->setLogger($logger);

        $auditLogger = new AuditLogger($logger);
        $this->requestProcessor->setAuditLogger($auditLogger);
    }

    /**
     * @return array<string, int|float|string>
     */
    #[Override]
    public function getMetrics(): array
    {
        $this->metrics->setActiveConnections($this->connectionPool->count());
        $metrics = $this->metrics->getMetrics();
        $metrics['memory_usage'] = $this->memoryMonitor->getUsage();
        $metrics['memory_peak'] = $this->memoryMonitor->getPeak();
        $metrics['memory_limit'] = $this->config->memoryLimit;
        $metrics['memory_usage_percent'] = round($this->memoryMonitor->getUsagePercent(), 2);

        return $metrics;
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
        $this->webSocketHandler->attachWebSocketServer($path, $ws);
        $this->hasWebSocket = true;
    }

    private function createSocket(): SocketInterface
    {
        if ($this->config->ssl) {
            $cert = $this->config->sslCert;
            $key = $this->config->sslKey;

            if (null === $cert || null === $key) {
                throw new InvalidConfigException('SSL enabled but certificate or key not provided');
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
        if (null === $this->socket) {
            return;
        }

        $acceptedCount = $this->connectionManager->acceptFromServerSocket(
            $this->socket,
            $this->config->maxAcceptsPerCycle,
            $this->config->debugMode,
        );

        if ($this->config->debugMode && $acceptedCount >= $this->config->maxAcceptsPerCycle) {
            $this->logger->debug('Max accepts per cycle reached', [
                'accepted' => $acceptedCount,
                'limit' => $this->config->maxAcceptsPerCycle,
                'note' => 'Deferring remaining connections to next cycle',
            ]);
        }
    }

    private function readFromConnections(): void
    {
        $resources = [];
        $resourceToConnection = [];
        $invalidConnections = [];

        foreach ($this->connectionPool as $connection) {
            if (false === $connection->isValid()) {
                $invalidConnections[] = $connection;
                continue;
            }

            $socket = $connection->getSocket();
            $internalResource = $socket instanceof StreamSocketResource
                ? $socket->getInternalResource()
                : null;

            if (null === $internalResource) {
                $invalidConnections[] = $connection;
                continue;
            }

            $key = $internalResource instanceof Socket
                ? 'socket_' . spl_object_id($internalResource)
                : 'stream_' . (int) $internalResource;

            $resources[] = $internalResource;
            $resourceToConnection[$key] = $connection;
        }

        foreach ($invalidConnections as $connection) {
            $this->connectionManager->closeConnectionWithMetrics($connection);
        }

        if ([] === $resources) {
            return;
        }

        $onDataCallback = function (ConnectionInterface $conn): void {
            if ($this->httpParser->hasCompleteHeaders($conn->getBuffer())) {
                if (false === $this->handleCorsPreflight($conn)) {
                    $this->requestProcessor->processRequest($conn);
                }
            }
        };

        $ready = StreamSocketResource::select($resources);

        if (null === $ready) {
            return;
        }

        foreach ($ready as $readyResource) {
            $key = $readyResource instanceof Socket
                ? 'socket_' . spl_object_id($readyResource)
                : 'stream_' . (int) $readyResource;

            $connection = $resourceToConnection[$key] ?? null;
            if (null === $connection) {
                continue;
            }

            if ($this->hasWebSocket && $this->webSocketHandler->hasWebSocketConnection($connection)) {
                $wsConn = $this->webSocketHandler->getWebSocketConnection($connection);
                if (null !== $wsConn) {
                    $this->webSocketHandler->processWebSocketDataDirect($connection, $wsConn);
                }
                continue;
            }

            $this->connectionManager->readFromConnectionDirect($connection, $this->config->bufferSize, $onDataCallback);
        }
    }

    private function cleanupTimedOutConnections(): void
    {
        $this->connectionManager->cleanupTimedOut($this->config->connectionTimeout);
    }

    private function getActiveConnectionCount(): int
    {
        return $this->connectionPool->count();
    }

    private function checkMemoryLimit(): void
    {
        if (false === $this->memoryMonitor->check()) {
            $this->logger->critical('Memory limit exceeded', [
                'limit' => $this->config->memoryLimit,
                'current' => $this->memoryMonitor->getUsage(),
            ]);

            throw new MemoryLimitExceededException(
                sprintf(
                    'Memory limit exceeded: %d bytes used, limit is %d bytes',
                    $this->memoryMonitor->getUsage(),
                    $this->config->memoryLimit,
                ),
            );
        }

        if ($this->memoryMonitor->isWarningThreshold(80)) {
            $now = time();
            if ($now - $this->lastMemoryWarningTime >= 60) {
                $this->lastMemoryWarningTime = $now;
                $this->logger->warning('Memory usage approaching limit', [
                    'usage_percent' => round($this->memoryMonitor->getUsagePercent(), 2),
                ]);
            }
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
     * @param Socket|resource $clientSocket Client socket (Socket object or stream resource)
     * @param array{client_ip?: string, worker_id: int, worker_pid?: int} $metadata
     */
    #[Override]
    public function addExternalConnection(mixed $clientSocket, array $metadata): void
    {
        if (!isset($metadata['worker_id'])) {
            throw new InvalidConfigException('worker_id is required in metadata for addExternalConnection()');
        }

        $workerContext = ['worker_id' => $metadata['worker_id']];
        if (isset($metadata['worker_pid'])) {
            $workerContext['worker_pid'] = $metadata['worker_pid'];
        }
        $this->setWorkerContext($workerContext);

        $clientIp = $metadata['client_ip'] ?? '0.0.0.0';
        $clientPort = 0;

        $socketResource = ($clientSocket instanceof SocketResourceInterface)
            ? $clientSocket
            : new StreamSocketResource($clientSocket);

        $peerInfo = ClientIpResolver::resolveFromResource($socketResource);
        if (false !== $peerInfo) {
            $clientIp = $peerInfo['ip'];
            $clientPort = $peerInfo['port'];
        } else {
            $this->logger->warning('Failed to get peer name', [
                'fallback_ip' => $clientIp,
            ]);
        }
        $connection = new Connection($socketResource, $clientIp, $clientPort, $this->config->maxRequestSize);

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
        $this->isRunning = true;

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

    #[Override]
    public function unregisterFiber(Fiber $fiber): bool
    {
        $key = array_search($fiber, $this->fibers, true);

        if (false !== $key) {
            unset($this->fibers[$key]);

            $this->logger->debug('Fiber unregistered', [
                'total_fibers' => count($this->fibers),
                'worker_id' => $this->workerId,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get socket resource for Event Loop integration (EvIo)
     *
     * @return Socket|resource|null Socket resource or null if unavailable
     */
    #[Override]
    public function getSocketResource(): mixed
    {
        if ($this->notificationManager->isEnabled() && null !== $this->notificationManager->getReadSocket()) {
            return $this->notificationManager->getReadSocket();
        }

        if ($this->mode === ServerMode::Standalone && null !== $this->socket) {
            return $this->socket->getInternalResource();
        }

        /** @var Socket|resource|null */
        return $this->externalSocketResource;
    }

    #[Override]
    public function setExternalSocketResource(mixed $resource): void
    {
        $this->externalSocketResource = $resource;
    }

    #[Override]
    public function setEventLoopActive(bool $active): void
    {
        $this->eventLoopActive = $active;
    }

    #[Override]
    public function isEventLoopActive(): bool
    {
        return $this->eventLoopActive;
    }

    private function notifyEventLoop(): void
    {
        if (null === $this->notificationManager->getNotifySocket()) {
            return;
        }

        if ($this->eventLoopActive) {
            return;
        }

        if (false === $this->requestProcessor->hasRequest() && false === $this->requestProcessor->hasPendingResponse()) {
            return;
        }

        $this->notificationManager->notify();
    }

    #[Override]
    public function startWatchers(): void
    {
        if ($this->watchersStarted) {
            return;
        }

        if (false === $this->notificationManager->isEnabled()) {
            throw new ServerException('Notification must be enabled before starting watchers');
        }

        $listeningResource = $this->getListeningResource();

        if (null !== $listeningResource) {
            $stream = $this->exportResourceToStream($listeningResource);

            if (false !== $stream) {
                $this->listeningWatcher = new EvIo($stream, Ev::READ, function (): void {
                    $this->handleListeningSocketReadable();
                });
                $this->listeningWatcher->start();
            }
        }

        foreach ($this->connectionPool->getAll() as $connection) {
            $this->startClientWatcher($connection);
        }

        $this->watchersStarted = true;

        $this->logger->debug('Server watchers started', [
            'listening' => null !== $this->listeningWatcher,
            'clients' => count($this->clientWatchers),
        ]);
    }

    #[Override]
    public function stopWatchers(): void
    {
        foreach ($this->clientWatchers as $watcher) {
            $watcher->stop();
        }
        $this->clientWatchers = [];

        if (null !== $this->listeningWatcher) {
            $this->listeningWatcher->stop();
        }
        $this->listeningWatcher = null;

        $this->watchersStarted = false;

        $this->logger->debug('Server watchers stopped');
    }

    #[Override]
    public function hasWatchers(): bool
    {
        return $this->watchersStarted;
    }

    #[Override]
    public function getNotificationReadStream(): mixed
    {
        if (null !== $this->notificationStream) {
            return $this->notificationStream;
        }

        $socket = $this->notificationManager->getReadSocket();

        if (null === $socket) {
            return null;
        }

        $stream = $this->exportToStream($socket);

        if (false === $stream) {
            return null;
        }

        $this->notificationStream = $stream;

        return $this->notificationStream;
    }

    /**
     * @return Socket|resource|null
     */
    private function getListeningResource(): mixed
    {
        if (null !== $this->socket) {
            return $this->socket->getInternalResource();
        }

        if (null !== $this->externalSocketResource && $this->externalSocketResource instanceof Socket) {
            return $this->externalSocketResource;
        }

        return null;
    }

    /**
     * @return resource|false
     */
    private function exportToStream(Socket|SocketResourceInterface $socket)
    {
        $socketResource = ($socket instanceof SocketResourceInterface)
            ? $socket
            : new StreamSocketResource($socket);

        $stream = $socketResource->exportStream();

        if (false === $stream) {
            $this->logger->warning('socket_export_stream failed');
        }

        return $stream;
    }

    /**
     * @param Socket|resource $resource
     *
     * @return resource|false
     */
    private function exportResourceToStream(mixed $resource)
    {
        if ($resource instanceof Socket) {
            return $this->exportToStream($resource);
        }

        return $resource;
    }

    private function startClientWatcher(ConnectionInterface $connection): void
    {
        $connectionId = $this->getConnectionId($connection);

        if (isset($this->clientWatchers[$connectionId])) {
            return;
        }

        $resource = $connection->getSocket()->getInternalResource();

        if (null === $resource) {
            return;
        }

        $stream = $this->exportResourceToStream($resource);

        if (false === $stream) {
            return;
        }

        $watcher = new EvIo($stream, Ev::READ, function () use ($connection): void {
            $this->handleClientSocketReadable($connection);
        });

        $watcher->start();
        $this->clientWatchers[$connectionId] = $watcher;
    }

    private function handleClientSocketReadable(ConnectionInterface $connection): void
    {
        $data = $connection->read($this->config->bufferSize);

        if (false === $data || '' === $data) {
            $this->closeConnection($connection);
            return;
        }

        $connection->appendToBuffer($data);

        if ($connection->isClosed()) {
            $this->closeConnection($connection);
            return;
        }

        $buffer = $connection->getBuffer();

        if (false === $this->httpParser->hasCompleteHeaders($buffer)) {
            return;
        }

        try {
            if ($this->handleCorsPreflight($connection)) {
                return;
            }

            $this->requestProcessor->processRequest($connection);

            $this->notifyEventLoop();
        } catch (Throwable $e) {
            $this->logger->error('Failed to process request', [
                'error' => $e->getMessage(),
            ]);
            $this->closeConnection($connection);
        }
    }

    private function handleListeningSocketReadable(): void
    {
        $listeningSocket = $this->getListeningSocket();

        if (null === $listeningSocket) {
            return;
        }

        $accepted = $this->connectionManager->acceptFromServerSocket(
            $listeningSocket,
            $this->config->maxAcceptsPerCycle,
            $this->config->debugMode,
        );

        if ($accepted > 0) {
            foreach ($this->connectionPool->getAll() as $connection) {
                $this->startClientWatcher($connection);
            }
        }
    }

    private function getListeningSocket(): ?SocketInterface
    {
        if (null !== $this->socket) {
            return $this->socket;
        }

        if (null !== $this->externalSocketResource && $this->externalSocketResource instanceof Socket) {
            return new ExistingSocket($this->externalSocketResource);
        }

        return null;
    }

    private function closeConnection(ConnectionInterface $connection): void
    {
        $connectionId = $this->getConnectionId($connection);

        if (isset($this->clientWatchers[$connectionId])) {
            $this->clientWatchers[$connectionId]->stop();
            unset($this->clientWatchers[$connectionId]);
        }

        $this->connectionManager->closeConnectionWithMetrics($connection);
    }

    private function getConnectionId(ConnectionInterface $connection): int
    {
        return spl_object_id($connection->getSocket());
    }

    private function handleCorsPreflight(ConnectionInterface $connection): bool
    {
        if (null === $this->corsService) {
            return false;
        }

        $buffer = $connection->getBuffer();

        if (!str_starts_with($buffer, 'OPTIONS ')) {
            return false;
        }

        $pos = strpos($buffer, "\r\n\r\n");

        if (false === $pos) {
            return false;
        }

        $headerBlock = substr($buffer, 0, $pos);
        $consumed = strlen($headerBlock) + 4;

        $request = $this->requestParser->parse(
            substr($buffer, 0, $consumed),
            $connection->getRemoteAddress(),
            $connection->getRemotePort(),
        );

        if (false === $this->corsService->isPreflightRequest($request)) {
            return false;
        }

        $origin = $request->getHeaderLine('Origin');

        if ($this->corsService->isOriginAllowed($origin)) {
            $response = $this->corsService->createPreflightResponse($origin);
        } else {
            $this->logger->warning('CORS preflight rejected: origin not allowed', [
                'origin' => $origin,
            ]);
            $response = new Response(204);
        }

        $connection->incrementRequestCount();

        $this->requestProcessor->resolveKeepAlive($connection, $request);

        $this->requestProcessor->sendResponse($connection, $response);
        $connection->consumeBuffer($consumed);

        return true;
    }
}
