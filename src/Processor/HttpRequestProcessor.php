<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Connection\ConnectionManagerInterface;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Handler\StaticFileHandler;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Notification\EventLoopNotifierInterface;
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
use Throwable;

final class HttpRequestProcessor implements RequestProcessorInterface
{
    private int $requestIdCounter = 0;

    private ?WebSocketUpgradeHandlerInterface $webSocketUpgradeHandler = null;

    private ?EventLoopNotifierInterface $eventLoopNotifier = null;

    private ?CorsService $corsService = null;

    private ?AuditLoggerInterface $auditLogger = null;

    private ?ConnectionManagerInterface $connectionManager = null;

    public function __construct(
        private readonly ServerConfig $config,
        private readonly HttpParser $httpParser,
        private readonly RequestParser $requestParser,
        private readonly ResponseWriter $responseWriter,
        private readonly ConnectionPool $connectionPool,
        private readonly ServerMetrics $metrics,
        private readonly TempFileManager $tempFileManager,
        private readonly RequestQueueInterface $requestQueue,
        private readonly ResponseSenderInterface $responseSender,
        private readonly ?StaticFileHandler $staticFileHandler = null,
        private readonly ?RateLimiter $rateLimiter = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setWebSocketUpgradeHandler(WebSocketUpgradeHandlerInterface $handler): void
    {
        $this->webSocketUpgradeHandler = $handler;
    }

    public function setEventLoopNotifier(EventLoopNotifierInterface $notifier): void
    {
        $this->eventLoopNotifier = $notifier;
    }

    public function setCorsService(CorsService $corsService): void
    {
        $this->corsService = $corsService;
    }

    public function setAuditLogger(AuditLoggerInterface $auditLogger): void
    {
        $this->auditLogger = $auditLogger;
    }

    public function setConnectionManager(ConnectionManagerInterface $connectionManager): void
    {
        $this->connectionManager = $connectionManager;
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

            if (null !== $this->webSocketUpgradeHandler && Handshake::isWebSocketRequest($request)) {
                $this->webSocketUpgradeHandler->handleUpgrade($connection, $request);
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

                $this->resolveKeepAlive($connection, $request);

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

            $corsOrigin = $this->resolveCorsOrigin($request);

            $this->requestQueue->enqueue($requestData, [
                'connection' => $connection,
                'timestamp' => microtime(true),
                'cors_origin' => $corsOrigin,
            ]);

            $this->metrics->incrementRequests();

            if (null !== $this->eventLoopNotifier) {
                $this->eventLoopNotifier->notify();
            }

            $connection->consumeBuffer($consumed);
            $connection->incrementRequestCount();

            $this->resolveKeepAlive($connection, $request);
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

        $this->responseSender->send($connection, $response);

        if (false === $connection->isKeepAlive()) {
            $this->closeConnection($connection);
        }
    }

    #[Override]
    public function sendErrorResponse(ConnectionInterface $connection, int $statusCode, string $message): void
    {
        $this->responseSender->sendError($connection, $statusCode, $message);
        $this->closeConnection($connection);
    }

    #[Override]
    public function generateRequestId(): string
    {
        return 'req_' . $this->requestIdCounter++;
    }

    public function hasRequest(): bool
    {
        return $this->requestQueue->hasRequest();
    }

    public function getRequest(): ?RequestData
    {
        return $this->requestQueue->dequeue();
    }

    public function respond(ResponseData $responseData): void
    {
        $requestId = $responseData->requestId;

        $data = $this->requestQueue->getContext($requestId);

        if (null === $data) {
            $this->logger->warning('respond() called with invalid request ID', [
                'request_id' => $requestId,
            ]);
            return;
        }

        $connection = $data['connection'];
        $this->requestQueue->remove($requestId);

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
        return $this->requestQueue->hasPendingResponse();
    }

    public function getPendingRequestId(): ?string
    {
        return $this->requestQueue->getPendingRequestId();
    }

    public function getRequestConnection(string $requestId): ?ConnectionInterface
    {
        $context = $this->requestQueue->getContext($requestId);
        return $context['connection'] ?? null;
    }

    public function removeRequestConnection(string $requestId): void
    {
        $this->requestQueue->remove($requestId);
    }

    public function removeConnectionsByConnection(ConnectionInterface $connection): void
    {
        $this->requestQueue->removeByConnection($connection);
    }

    public function cleanupStaleRequests(int $timeout): void
    {
        $this->requestQueue->cleanupStale($timeout, function (ConnectionInterface $connection, string $requestId): void {
            $this->closeConnection($connection);

            $this->logger->warning('Request timeout, cleaned up', [
                'request_id' => $requestId,
            ]);
        });
    }

    public function reset(): void
    {
        $this->requestQueue->reset();
        $this->requestIdCounter = 0;
        $this->tempFileManager->cleanup();
    }

    public function getPendingRequestCount(): int
    {
        return $this->requestQueue->getPendingRequestCount();
    }

    public function getQueueCount(): int
    {
        return $this->requestQueue->getQueueCount();
    }

    private function closeConnection(ConnectionInterface $connection): void
    {
        assert(null !== $this->connectionManager);
        $this->connectionManager->closeConnectionWithMetrics($connection);
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

    public function resolveKeepAlive(
        ConnectionInterface $connection,
        ServerRequestInterface $request,
    ): void {
        $connectionHeader = $request->getHeaderLine('Connection');
        $keepAlive = $this->config->enableKeepAlive
            && (strcasecmp($connectionHeader, 'close') !== 0)
            && $connection->getRequestCount() < $this->config->keepAliveMaxRequests;
        $connection->setKeepAlive($keepAlive);
    }
}
