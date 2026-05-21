<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

class RequestIdFlowTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }
        parent::tearDown();
    }
    #[Test]
    public function it_handles_complete_request_response_cycle(): void
    {
        $config = new ServerConfig(port: 18220);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $request = new ServerRequest('GET', '/api/users');
        $requestData = new RequestData('req_cycle_test', $request, 42);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $contextsProperty->setValue($requestQueue, [
            'req_cycle_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertTrue($this->server->hasPendingResponse());

        $retrievedRequest = $this->server->getRequest();

        self::assertInstanceOf(RequestData::class, $retrievedRequest);
        self::assertSame('req_cycle_test', $retrievedRequest->id);
        self::assertSame('GET', $retrievedRequest->request->getMethod());
        self::assertSame('/api/users', (string) $retrievedRequest->request->getUri());
        self::assertSame(42, $retrievedRequest->connectionId);

        $response = new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
        $responseData = $retrievedRequest->respond($response);

        $this->server->respond($responseData);

        self::assertFalse($this->server->hasPendingResponse());
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_generates_unique_ids_for_each_request(): void
    {
        $config = new ServerConfig(port: 18221);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');

        $ids = [];
        $requestCount = 50;

        for ($i = 0; $i < $requestCount; $i++) {
            $id = $requestProcessor->generateRequestId();
            $ids[] = $id;

            $connection = $this->createStub(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);

            $request = new ServerRequest('GET', "/test-$i");
            $requestData = new RequestData($id, $request, $i);
            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);
        }

        $uniqueIds = array_unique($ids);

        self::assertCount($requestCount, $uniqueIds);
        self::assertCount($requestCount, $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_removes_mapping_after_response(): void
    {
        $config = new ServerConfig(port: 18222);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $request = new ServerRequest('POST', '/api/data');
        $requestData = new RequestData('req_cleanup_test', $request, 1);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $contextsProperty->setValue($requestQueue, [
            'req_cleanup_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertArrayHasKey('req_cleanup_test', $contextsProperty->getValue($requestQueue));
        self::assertTrue($this->server->hasPendingResponse());

        $response = new Response(201, [], 'Created');
        $this->server->respond(new ResponseData('req_cleanup_test', $response));

        self::assertArrayNotHasKey('req_cleanup_test', $contextsProperty->getValue($requestQueue));
        self::assertFalse($this->server->hasPendingResponse());
    }

    #[Test]
    public function it_handles_keep_alive_connections(): void
    {
        $config = new ServerConfig(port: 18223);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(true);
        $connection->method('write')->willReturn(100);

        $requestCount = 3;
        $requestIds = [];

        for ($i = 0; $i < $requestCount; $i++) {
            $id = "req_keepalive_$i";
            $requestIds[] = $id;

            $request = new ServerRequest('GET', "/keep-alive-test-$i");
            $requestData = new RequestData($id, $request, 1);

            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);
        }

        self::assertCount($requestCount, $contextsProperty->getValue($requestQueue));

        foreach ($requestIds as $id) {
            $this->server->respond(new ResponseData($id, new Response(200, [], 'OK')));
        }

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_integrates_with_event_loop_simulation(): void
    {
        $config = new ServerConfig(port: 18224);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $processedRequests = [];

        $connections = [];
        for ($i = 0; $i < 5; $i++) {
            $connection = $this->createStub(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connection->method('isKeepAlive')->willReturn(false);
            $connection->method('write')->willReturn(100);
            $connections[$i] = $connection;
        }

        for ($i = 0; $i < 5; $i++) {
            $id = "req_loop_$i";
            $request = new ServerRequest('GET', "/event-loop-$i");
            $requestData = new RequestData($id, $request, $i);

            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping[$id] = [
                'connection' => $connections[$i],
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);
        }

        $iteration = 0;
        while ($requestQueue->hasRequest()) {
            $requestData = $this->server->getRequest();

            if ($requestData === null) {
                break;
            }

            $processedRequests[] = $requestData->id;

            $response = new Response(200, [], "Response for {$requestData->id}");
            $this->server->respond(new ResponseData($requestData->id, $response));

            $iteration++;
        }

        self::assertCount(5, $processedRequests);
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_works_with_convenience_method(): void
    {
        $config = new ServerConfig(port: 18225);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $request = new ServerRequest('PUT', '/api/resource/123');
        $requestData = new RequestData('req_convenience', $request, 99);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $contextsProperty->setValue($requestQueue, [
            'req_convenience' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $retrievedRequest = $this->server->getRequest();
        self::assertInstanceOf(RequestData::class, $retrievedRequest);

        $response = new Response(200, ['X-Custom' => 'header'], 'Resource updated');
        $responseData = $retrievedRequest->respond($response);

        self::assertInstanceOf(ResponseData::class, $responseData);
        self::assertSame('req_convenience', $responseData->requestId);
        self::assertSame($response, $responseData->response);

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_preserves_request_metadata_through_cycle(): void
    {
        $config = new ServerConfig(port: 18226);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $originalRequest = (new ServerRequest('POST', '/api/submit'))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Request-ID', 'custom-123')
            ->withParsedBody(['data' => 'test']);

        $requestData = new RequestData('req_metadata', $originalRequest, 777);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $contextsProperty->setValue($requestQueue, [
            'req_metadata' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $retrievedRequest = $this->server->getRequest();

        self::assertInstanceOf(RequestData::class, $retrievedRequest);
        self::assertSame('req_metadata', $retrievedRequest->id);
        self::assertSame(777, $retrievedRequest->connectionId);
        self::assertSame('POST', $retrievedRequest->request->getMethod());
        self::assertSame('/api/submit', (string) $retrievedRequest->request->getUri());
        self::assertSame('application/json', $retrievedRequest->request->getHeaderLine('Content-Type'));
        self::assertSame('custom-123', $retrievedRequest->request->getHeaderLine('X-Request-ID'));

        $response = new Response(202, [], 'Accepted');
        $this->server->respond($retrievedRequest->respond($response));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_queue_fifo_order(): void
    {
        $config = new ServerConfig(port: 18227);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $requestOrder = [];

        for ($i = 0; $i < 10; $i++) {
            $connection = $this->createStub(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connection->method('isKeepAlive')->willReturn(false);
            $connection->method('write')->willReturn(100);

            $id = "req_fifo_$i";
            $request = new ServerRequest('GET', "/fifo-$i");
            $requestData = new RequestData($id, $request, $i);

            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);
        }

        while ($requestQueue->hasRequest()) {
            $requestData = $this->server->getRequest();
            if ($requestData !== null) {
                $requestOrder[] = $requestData->id;
                $this->server->respond(new ResponseData($requestData->id, new Response(200)));
            }
        }

        for ($i = 0; $i < 10; $i++) {
            self::assertSame("req_fifo_$i", $requestOrder[$i]);
        }
    }

    #[Test]
    public function it_returns_null_when_queue_empty(): void
    {
        $config = new ServerConfig(port: 18228);
        $this->server = new Server($config);

        $result = $this->server->getRequest();

        self::assertNull($result);
    }
}
