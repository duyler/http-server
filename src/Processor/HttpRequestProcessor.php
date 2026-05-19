<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Handler\StaticFileHandler;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\RateLimit\RateLimiter;
use Duyler\HttpServer\Security\AuditLoggerInterface;
use Duyler\HttpServer\Security\CorsService;
use Duyler\HttpServer\Upload\TempFileManager;
use Duyler\HttpServer\WebSocket\Handshake;
use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplQueue;
use Throwable;

final class HttpRequestProcessor implements RequestProcessorInterface
{
    private int $requestIdCounter = 0;

    private readonly SplQueue $requestQueue;

    /** @var array<string, array{connection: ConnectionInterface, timestamp: float, cors_origin: ?string}> */
    private array $requestConnections = [];

    /** @var callable(ConnectionInterface, ServerRequestInterface): void|null */
    private $webSocketHandler = null;

    /** @var callable(): void|null */
    private $notifyEventLoopCallback = null;

    private ?CorsService $corsService = null;

    private ?AuditLoggerInterface $auditLogger = null;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly HttpParser $httpParser,
        private readonly RequestParser $requestParser,
        private readonly ResponseWriter $responseWriter,
        private readonly ConnectionPool $connectionPool,
        private readonly ServerMetrics $metrics,
        private readonly TempFileManager $tempFileManager,
        private readonly ?StaticFileHandler $staticFileHandler = null,
        private readonly ?RateLimiter $rateLimiter = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->requestQueue = new SplQueue();
    }

    /**
     * @param callable(ConnectionInterface, ServerRequestInterface): void $handler
     */
    public function setWebSocketHandler(callable $handler): void
    {
        $this->webSocketHandler = $handler;
    }

    /**
     * @param callable(): void $callback
     */
    public function setNotifyEventLoopCallback(callable $callback): void
    {
        $this->notifyEventLoopCallback = $callback;
    }

    public function setCorsService(CorsService $corsService): void
    {
        $this->corsService = $corsService;
    }

    public function setAuditLogger(AuditLoggerInterface $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }

    #[Override]
    public function processRequest(ConnectionInterface $connection): void
    {
        try {
            $buffer = $connection->getBuffer();
            $connection->startRequestTimer();

            if ($connection->isRequestTimedOut($this->config->requestTimeout)) {
                $this->logger->warning('Request reading timeout', [
                    'remote' => $connection->getRemoteAddress(),
                    'timeout' => $this->config->requestTimeout,
                ]);

                if (null !== $this->auditLogger) {
                    $this->auditLogger->logSecurityEvent('request_timeout', [
                        'ip' => $connection->getRemoteAddress(),
                    ]);
                }

                $this->sendErrorResponse($connection, 408, 'Request Timeout');
                return;
            }

            [$headerBlock, $body] = $this->httpParser->splitHeadersAndBody($buffer);

            if (null === $connection->getCachedHeaders()) {
                $lines = explode("\r\n", $headerBlock);
                $headerText = implode("\r\n", array_slice($lines, 1));
                $headers = $this->httpParser->parseHeaders($headerText);
                $contentLength = $this->httpParser->getContentLength($headers);
                $connection->setCachedHeaders($headers);
                $connection->setExpectedContentLength($contentLength);
            } else {
                $headers = $connection->getCachedHeaders();
                $contentLength = $connection->getExpectedContentLength() ?? 0;
            }

            if (strlen($body) < $contentLength) {
                return;
            }

            $consumed = strlen($headerBlock) + 4 + $contentLength;

            if ($contentLength > $this->config->maxRequestSize) {
                $this->logger->warning('Request payload too large', [
                    'content_length' => $contentLength,
                    'max_allowed' => $this->config->maxRequestSize,
                ]);

                if (null !== $this->auditLogger) {
                    $this->auditLogger->logSecurityEvent('request_too_large', [
                        'ip' => $connection->getRemoteAddress(),
                        'content_length' => $contentLength,
                    ]);
                }

                $this->sendErrorResponse($connection, 413, 'Payload Too Large');
                return;
            }

            $request = $this->requestParser->parse(
                substr($buffer, 0, $consumed),
                $connection->getRemoteAddress(),
                $connection->getRemotePort(),
            );

            if (null !== $this->webSocketHandler && Handshake::isWebSocketRequest($request)) {
                ($this->webSocketHandler)($connection, $request);
                return;
            }

            if (null !== $this->rateLimiter
                && false === $this->rateLimiter->isAllowed($connection->getRemoteAddress())
            ) {
                $this->logger->warning('Rate limit exceeded', [
                    'remote' => $connection->getRemoteAddress(),
                ]);

                if (null !== $this->auditLogger) {
                    $this->auditLogger->logRateLimitExceeded(
                        $connection->getRemoteAddress(),
                        $connection->getRequestCount(),
                    );
                }

                $resetTime = $this->rateLimiter->getResetTime($connection->getRemoteAddress());
                $response = new Response(429, [
                    'Content-Type' => 'text/plain',
                    'Retry-After' => (string) $resetTime,
                    'X-RateLimit-Limit' => (string) $this->config->rateLimitRequests,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) (time() + $resetTime),
                ], 'Too Many Requests');

                $this->sendResponse($connection, $response);
                $connection->consumeBuffer($consumed);
                return;
            }

            if (null !== $this->staticFileHandler && $this->staticFileHandler->isStaticFile($request)) {
                $connection->incrementRequestCount();

                $connectionHeader = $request->getHeaderLine('Connection');
                $keepAlive = $this->config->enableKeepAlive
                    && (strcasecmp($connectionHeader, 'close') !== 0)
                    && $connection->getRequestCount() < $this->config->keepAliveMaxRequests;

                $connection->setKeepAlive($keepAlive);

                $response = $this->staticFileHandler->handle($request);

                if (null !== $response) {
                    $this->sendResponse($connection, $response);
                }

                $connection->consumeBuffer($consumed);
                return;
            }

            $requestId = $this->generateRequestId();
            $connectionId = spl_object_id($connection->getSocket());

            $requestData = new RequestData($requestId, $request, $connectionId);

            $this->requestQueue->enqueue($requestData);

            $corsOrigin = $this->resolveCorsOrigin($request);

            $this->requestConnections[$requestId] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
                'cors_origin' => $corsOrigin,
            ];

            $this->metrics->incrementRequests();

            if (null !== $this->notifyEventLoopCallback) {
                ($this->notifyEventLoopCallback)();
            }

            $connection->consumeBuffer($consumed);
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

    #[Override]
    public function sendResponse(ConnectionInterface $connection, ResponseInterface $response): void
    {
        if (false === $connection->isValid()) {
            $this->closeConnection($connection);
            return;
        }

        if (false === $response->hasHeader('Content-Length')) {
            $body = $response->getBody();
            $size = $body->getSize();

            if (null !== $size) {
                $response = $response->withHeader('Content-Length', (string) $size);
            } else {
                $bodyContents = (string) $body;
                $size = strlen($bodyContents);
                $response = $response->withHeader('Content-Length', (string) $size);

                $newBody = \Nyholm\Psr7\Stream::create($bodyContents);
                $response = $response->withBody($newBody);
            }
        }

        if (false === $connection->isKeepAlive()) {
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

        if (false === $written) {
            $this->logger->warning('Failed to write response', [
                'remote' => $connection->getRemoteAddress(),
            ]);
            $this->closeConnection($connection);
            return;
        }

        if (false === $connection->isKeepAlive()) {
            $this->closeConnection($connection);
        }
    }

    #[Override]
    public function sendErrorResponse(ConnectionInterface $connection, int $statusCode, string $message): void
    {
        $response = (new Response($statusCode))
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Connection', 'close');

        $response->getBody()->write($message);

        $httpResponse = $this->responseWriter->write($response);
        $connection->write($httpResponse);

        $this->closeConnection($connection);
    }

    #[Override]
    public function generateRequestId(): string
    {
        return 'req_' . $this->requestIdCounter++;
    }

    public function hasRequest(): bool
    {
        return false === $this->requestQueue->isEmpty();
    }

    public function getRequest(): ?RequestData
    {
        if ($this->requestQueue->isEmpty()) {
            return null;
        }

        $request = $this->requestQueue->dequeue();
        assert($request instanceof RequestData);

        return $request;
    }

    public function respond(ResponseData $responseData): void
    {
        $requestId = $responseData->requestId;

        if (!isset($this->requestConnections[$requestId])) {
            $this->logger->warning('respond() called with invalid request ID', [
                'request_id' => $requestId,
                'valid_ids' => array_keys($this->requestConnections),
            ]);
            return;
        }

        $data = $this->requestConnections[$requestId];
        $connection = $data['connection'];

        unset($this->requestConnections[$requestId]);

        if (false === $connection->isValid()) {
            $this->closeConnection($connection);
            return;
        }

        try {
            $response = $responseData->response;

            $corsOrigin = $data['cors_origin'] ?? null;
            if (null !== $this->corsService && null !== $corsOrigin) {
                $response = $this->corsService->addCorsHeaders($response, $corsOrigin);
            }

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
                'request_id' => $requestId,
                'status' => $responseData->response->getStatusCode(),
            ]);
            $this->closeConnection($connection);
        }
    }

    public function hasPendingResponse(): bool
    {
        return count($this->requestConnections) > 0;
    }

    public function getPendingRequestId(): ?string
    {
        foreach ($this->requestConnections as $requestId => $data) {
            return $requestId;
        }

        return null;
    }

    public function getRequestConnection(string $requestId): ?ConnectionInterface
    {
        return $this->requestConnections[$requestId]['connection'] ?? null;
    }

    public function removeRequestConnection(string $requestId): void
    {
        if (isset($this->requestConnections[$requestId])) {
            unset($this->requestConnections[$requestId]);
        }
    }

    public function removeConnectionsByConnection(ConnectionInterface $connection): void
    {
        foreach ($this->requestConnections as $requestId => $data) {
            if ($data['connection'] === $connection) {
                unset($this->requestConnections[$requestId]);
            }
        }
    }

    public function cleanupStaleRequests(int $timeout): void
    {
        $now = microtime(true);

        foreach ($this->requestConnections as $requestId => $data) {
            if (($now - $data['timestamp']) > $timeout) {
                $this->closeConnection($data['connection']);
                unset($this->requestConnections[$requestId]);

                $this->logger->warning('Request timeout, cleaned up', [
                    'request_id' => $requestId,
                ]);
            }
        }
    }

    public function reset(): void
    {
        while (false === $this->requestQueue->isEmpty()) {
            $this->requestQueue->dequeue();
        }
        $this->requestConnections = [];
        $this->requestIdCounter = 0;
        $this->tempFileManager->cleanup();
    }

    public function getPendingRequestCount(): int
    {
        return count($this->requestConnections);
    }

    public function getQueueCount(): int
    {
        return $this->requestQueue->count();
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    private function closeConnection(ConnectionInterface $connection): void
    {
        if ($this->config->debugMode) {
            $this->logger->debug('Closing connection', [
                'remote' => $connection->getRemoteAddress(),
                'requests_handled' => $connection->getRequestCount(),
            ]);
        }

        $this->removeConnectionsByConnection($connection);

        $connection->close();
        $this->connectionPool->remove($connection);
        $this->metrics->incrementClosedConnections();
    }

    private function resolveCorsOrigin(ServerRequestInterface $request): ?string
    {
        if (null === $this->corsService) {
            return null;
        }

        if (false === $this->corsService->isCorsRequest($request)) {
            return null;
        }

        $origin = $request->getHeaderLine('Origin');

        if ($this->corsService->isOriginAllowed($origin)) {
            return $origin;
        }

        $this->logger->warning('CORS request rejected: origin not allowed', [
            'origin' => $origin,
        ]);

        return null;
    }
}
