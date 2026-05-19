<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\Connection;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Socket\StreamSocketResource;
use Duyler\HttpServer\Upload\TempFileManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HttpRequestProcessorPipeliningTest extends TestCase
{
    /** @var resource */
    private mixed $socket;

    private Connection $connection;

    private HttpRequestProcessor $processor;

    private StreamSocketResource $socketResource;

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
        $connectionPool = new ConnectionPool(100);
        $metrics = new ServerMetrics();

        $this->processor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
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
    public function pipeline_processes_two_requests_in_single_buffer(): void
    {
        $request1 = "GET /first HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $request2 = "GET /second HTTP/1.1\r\nHost: example.com\r\n\r\n";

        $this->connection->appendToBuffer($request1 . $request2);

        $this->processor->processRequest($this->connection);
        $this->assertTrue($this->processor->hasRequest());
        $requestData = $this->processor->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('/first', $requestData->request->getUri()->getPath());

        $remainingBuffer = $this->connection->getBuffer();
        $this->assertSame($request2, $remainingBuffer);

        $this->processor->processRequest($this->connection);
        $this->assertTrue($this->processor->hasRequest());
        $requestData2 = $this->processor->getRequest();
        $this->assertNotNull($requestData2);
        $this->assertSame('/second', $requestData2->request->getUri()->getPath());

        $this->assertSame('', $this->connection->getBuffer());
    }

    #[Test]
    public function pipeline_preserves_extra_data_after_second_request(): void
    {
        $request1 = "GET /first HTTP/1.1\r\nHost: example.com\r\n\r\n";
        $extraData = "GET /third HT";

        $this->connection->appendToBuffer($request1 . $extraData);

        $this->processor->processRequest($this->connection);

        $this->assertSame($extraData, $this->connection->getBuffer());
    }

    #[Test]
    public function pipeline_with_post_request_preserves_body_boundary(): void
    {
        $body = '{"key":"value"}';
        $bodyLength = strlen($body);
        $request1 = "POST /api HTTP/1.1\r\nHost: example.com\r\nContent-Length: {$bodyLength}\r\n\r\n" . $body;
        $request2 = "GET /next HTTP/1.1\r\nHost: example.com\r\n\r\n";

        $this->connection->appendToBuffer($request1 . $request2);

        $this->processor->processRequest($this->connection);

        $this->assertTrue($this->processor->hasRequest());
        $requestData = $this->processor->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('POST', $requestData->request->getMethod());
        $this->assertSame('/api', $requestData->request->getUri()->getPath());

        $this->assertSame($request2, $this->connection->getBuffer());
    }
}
