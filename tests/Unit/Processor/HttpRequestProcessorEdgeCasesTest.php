<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Connection\ConnectionManagerInterface;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Processor\RequestQueue;
use Duyler\HttpServer\Processor\ResponseSender;
use Duyler\HttpServer\RateLimit\RateLimiter;
use Duyler\HttpServer\Security\AuditLoggerInterface;
use Duyler\HttpServer\Security\CorsService;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\Upload\TempFileManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

final class HttpRequestProcessorEdgeCasesTest extends TestCase
{
    /** @var resource */
    private mixed $socket;

    private StreamSocketResource $socketResource;

    private Connection $connection;

    private HttpRequestProcessor $processor;

    private ServerMetrics $metrics;

    #[Override]
    protected function setUp(): void
    {
        $this->socket = fopen('php://memory', 'r+');
        $this->socketResource = new StreamSocketResource($this->socket);

        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $this->metrics = new ServerMetrics();

        $this->processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $this->metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );

        $this->connection = new Connection($this->socketResource, '127.0.0.1', 12345);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    #[Test]
    public function process_request_handles_timeout_with_audit_logger(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logSecurityEvent')
            ->with('request_timeout', $this->callback(fn(array $ctx): bool => '127.0.0.1' === $ctx['ip']));

        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $metrics = new ServerMetrics();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics')
            ->with($this->isInstanceOf(ConnectionInterface::class));
        $processor->setConnectionManager($connectionManager);
        $processor->setAuditLogger($auditLogger);

        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);
        $connection->startRequestTimer();

        $startTimeProperty = new ReflectionProperty(Connection::class, 'requestStartTime');
        $startTimeProperty->setValue($connection, microtime(true) - 100);

        $connection->appendToBuffer("GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");

        $processor->processRequest($connection);
    }

    #[Test]
    public function process_request_handles_payload_too_large_with_audit_logger(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logSecurityEvent')
            ->with('request_too_large', $this->callback(fn(array $ctx): bool => $ctx['content_length'] > 0));

        $config = new ServerConfig(maxRequestSize: 1024);
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $metrics = new ServerMetrics();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics');
        $processor->setConnectionManager($connectionManager);
        $processor->setAuditLogger($auditLogger);

        $body = str_repeat('x', 2048);
        $connection = new Connection($this->socketResource, '127.0.0.1', 12345);
        $connection->appendToBuffer("POST /upload HTTP/1.1\r\nHost: example.com\r\nContent-Length: 2048\r\n\r\n" . $body);

        $processor->processRequest($connection);
    }

    #[Test]
    public function process_request_rejects_rate_limited_request(): void
    {
        $config = new ServerConfig(enableRateLimit: true, rateLimitRequests: 1, rateLimitWindow: 60);
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $metrics = new ServerMetrics();
        $rateLimiter = new RateLimiter(1, 60);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logRateLimitExceeded')
            ->with('127.0.0.1', $this->anything());

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            $rateLimiter,
            new NullLogger(),
        );

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics');
        $processor->setConnectionManager($connectionManager);
        $processor->setAuditLogger($auditLogger);

        $rateLimiter->isAllowed('127.0.0.1');

        $socket = fopen('php://memory', 'r+');
        $socketResource = new StreamSocketResource($socket);
        $connection = new Connection($socketResource, '127.0.0.1', 12345);
        $connection->appendToBuffer("GET /limited HTTP/1.1\r\nHost: example.com\r\n\r\n");

        $processor->processRequest($connection);

        fclose($socket);
    }

    #[Test]
    public function respond_with_invalid_request_id_logs_warning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('respond() called with invalid request ID', $this->anything());

        $this->processor->setLogger($logger);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_nonexistent', $response);

        $this->processor->respond($responseData);
    }

    #[Test]
    public function respond_with_invalid_connection_closes_it(): void
    {
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics');
        $this->processor->setConnectionManager($connectionManager);

        $this->connection->appendToBuffer("GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $requestData = $this->processor->getRequest();
        $this->assertNotNull($requestData);

        $this->connection->close();

        $response = new Response(200, [], 'OK');
        $this->processor->respond($requestData->respond($response));
    }

    #[Test]
    public function respond_with_cors_headers_adds_them(): void
    {
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->never())
            ->method('closeConnectionWithMetrics');
        $this->processor->setConnectionManager($connectionManager);

        $corsService = new CorsService(
            allowedOrigins: ['https://example.com'],
            allowedMethods: ['GET'],
            allowedHeaders: ['Content-Type'],
        );

        $this->processor->setCorsService($corsService);

        $this->connection->appendToBuffer("GET /cors HTTP/1.1\r\nHost: example.com\r\nOrigin: https://example.com\r\n\r\n");

        $this->processor->processRequest($this->connection);

        $requestData = $this->processor->getRequest();
        $this->assertNotNull($requestData);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData($requestData->id, $response);

        $this->processor->respond($responseData);
    }

    #[Test]
    public function respond_increments_failed_metrics_on_error_status(): void
    {
        $this->connection->appendToBuffer("GET /fail HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $requestData = $this->processor->getRequest();
        $this->assertNotNull($requestData);

        $response = new Response(500, [], 'Internal Server Error');
        $this->processor->respond($requestData->respond($response));

        $this->assertSame(0, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function respond_handles_exception_and_closes_connection(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to send response', $this->anything());

        $mockConnection = $this->createStub(ConnectionInterface::class);
        $mockConnection->method('isValid')->willReturn(true);
        $mockConnection->method('isKeepAlive')->willReturn(true);
        $mockConnection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $mockConnection->method('getRemotePort')->willReturn(12345);
        $mockConnection->method('getSocket')->willReturn($this->socketResource);
        $mockConnection->method('write')->willThrowException(new RuntimeException('Write failed'));

        $requestQueue = new RequestQueue();
        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $metrics = new ServerMetrics();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            $requestQueue,
            new ResponseSender($config, $responseWriter),
            null,
            null,
            $logger,
        );

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics');
        $processor->setConnectionManager($connectionManager);

        $requestData = new \Duyler\HttpServer\Dto\RequestData('req_0', $this->createStub(\Psr\Http\Message\ServerRequestInterface::class), 1);
        $requestQueue->enqueue($requestData, [
            'connection' => $mockConnection,
            'timestamp' => microtime(true),
            'cors_origin' => null,
        ]);

        $response = new Response(200, [], 'OK');
        $processor->respond(new ResponseData('req_0', $response));
    }

    #[Test]
    public function send_response_closes_invalid_connection(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(false);

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics')
            ->with($connection);
        $this->processor->setConnectionManager($connectionManager);

        $response = new Response(200, [], 'OK');
        $this->processor->sendResponse($connection, $response);
    }

    #[Test]
    public function send_response_closes_connection_when_not_keep_alive(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics')
            ->with($connection);
        $this->processor->setConnectionManager($connectionManager);

        $response = new Response(200, [], 'OK');
        $this->processor->sendResponse($connection, $response);
    }

    #[Test]
    public function resolve_cors_origin_returns_null_when_no_cors_service(): void
    {
        $this->connection->appendToBuffer("GET /nocors HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $this->assertTrue($this->processor->hasRequest());
    }

    #[Test]
    public function process_request_catches_exception_and_sends_400(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to process request', $this->anything());

        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $metrics = new ServerMetrics();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($config, $responseWriter),
            null,
            null,
            $logger,
        );

        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics');
        $processor->setConnectionManager($connectionManager);

        $mockConnection = $this->createStub(ConnectionInterface::class);
        $mockConnection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $mockConnection->method('getRemotePort')->willReturn(12345);
        $mockConnection->method('startRequestTimer')->willThrowException(new RuntimeException('Simulated failure'));

        $processor->processRequest($mockConnection);
    }

    #[Test]
    public function get_request_connection_returns_null_for_unknown_id(): void
    {
        $result = $this->processor->getRequestConnection('req_unknown');
        $this->assertNull($result);
    }

    #[Test]
    public function remove_request_connection_removes_successfully(): void
    {
        $this->connection->appendToBuffer("GET /test HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $requestData = $this->processor->getRequest();
        $this->assertNotNull($requestData);

        $this->processor->removeRequestConnection($requestData->id);

        $result = $this->processor->getRequestConnection($requestData->id);
        $this->assertNull($result);
    }

    #[Test]
    public function get_queue_count_returns_zero_initially(): void
    {
        $this->assertSame(0, $this->processor->getQueueCount());
    }

    #[Test]
    public function get_pending_request_count_returns_zero_initially(): void
    {
        $this->assertSame(0, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function cleanup_stale_requests_removes_old_requests(): void
    {
        $connectionManager = $this->createMock(ConnectionManagerInterface::class);
        $connectionManager->expects($this->once())
            ->method('closeConnectionWithMetrics');
        $this->processor->setConnectionManager($connectionManager);

        $this->connection->appendToBuffer("GET /stale HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $this->processor->cleanupStaleRequests(0);
    }

    #[Test]
    public function has_pending_response_returns_false_when_empty(): void
    {
        $this->assertFalse($this->processor->hasPendingResponse());
    }

    #[Test]
    public function get_pending_request_id_returns_null_when_empty(): void
    {
        $this->assertNull($this->processor->getPendingRequestId());
    }

    #[Test]
    public function reset_clears_queue_and_counter(): void
    {
        $this->connection->appendToBuffer("GET /reset HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $this->processor->reset();

        $this->assertFalse($this->processor->hasRequest());
        $this->assertSame(0, $this->processor->getPendingRequestCount());
    }

    #[Test]
    public function remove_connections_by_connection_removes_matching(): void
    {
        $mockConnection = $this->createStub(ConnectionInterface::class);
        $mockConnection->method('getSocket')->willReturn($this->socketResource);
        $mockConnection->method('isValid')->willReturn(true);
        $mockConnection->method('getRemoteAddress')->willReturn('127.0.0.1');
        $mockConnection->method('getRemotePort')->willReturn(12345);

        $requestQueue = new RequestQueue();
        $config = new ServerConfig();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new \Duyler\HttpServer\Connection\ConnectionPool(100);
        $metrics = new ServerMetrics();

        $processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            $requestQueue,
            new ResponseSender($config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );

        $processor->setConnectionManager($this->createStub(ConnectionManagerInterface::class));

        $requestData = new \Duyler\HttpServer\Dto\RequestData('req_0', $this->createStub(\Psr\Http\Message\ServerRequestInterface::class), 1);
        $requestQueue->enqueue($requestData, [
            'connection' => $mockConnection,
            'timestamp' => microtime(true),
            'cors_origin' => null,
        ]);

        $this->assertTrue($processor->hasRequest());

        $processor->removeConnectionsByConnection($mockConnection);

        $this->assertFalse($processor->hasRequest());
    }

    #[Test]
    public function process_request_with_websocket_upgrade_handler(): void
    {
        $upgradeCalled = false;
        $upgradeHandler = new \Duyler\HttpServer\Processor\WebSocketUpgradeHandler(
            function (ConnectionInterface $conn, \Psr\Http\Message\ServerRequestInterface $req) use (&$upgradeCalled): void {
                $upgradeCalled = true;
            },
        );

        $this->processor->setWebSocketUpgradeHandler($upgradeHandler);

        $this->connection->appendToBuffer("GET /ws HTTP/1.1\r\nHost: example.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $this->processor->processRequest($this->connection);

        $this->assertTrue($upgradeCalled);
    }

    #[Test]
    public function process_request_with_event_loop_notifier(): void
    {
        $notified = false;
        $notifier = new \Duyler\HttpServer\Notification\EventLoopNotifier(
            function () use (&$notified): void {
                $notified = true;
            },
        );

        $this->processor->setEventLoopNotifier($notifier);

        $this->connection->appendToBuffer("GET /notify HTTP/1.1\r\nHost: example.com\r\n\r\n");
        $this->processor->processRequest($this->connection);

        $this->assertTrue($notified);
    }
}
