<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

class ServerRequestIdTest extends TestCase
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
        }
        parent::tearDown();
    }

    #[Test]
    public function it_generates_sequential_request_ids(): void
    {
        $config = new ServerConfig(port: 18085);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $processorProperty = $reflection->getProperty('requestProcessor');
        $processorProperty->setAccessible(true);
        $processor = $processorProperty->getValue($this->server);

        $processorReflection = new ReflectionClass($processor);
        $method = $processorReflection->getMethod('generateRequestId');

        $id1 = $method->invoke($processor);
        $id2 = $method->invoke($processor);
        $id3 = $method->invoke($processor);

        self::assertSame('req_0', $id1);
        self::assertSame('req_1', $id2);
        self::assertSame('req_2', $id3);
    }

    #[Test]
    public function it_creates_request_data_with_id(): void
    {
        $config = new ServerConfig(port: 18086);
        $this->server = new Server($config);

        $request = new \Nyholm\Psr7\ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        self::assertSame('req_123', $requestData->id);
        self::assertSame($request, $requestData->request);
        self::assertSame(42, $requestData->connectionId);
    }

    #[Test]
    public function it_creates_response_data_via_respond_method(): void
    {
        $request = new \Nyholm\Psr7\ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        $response = new Response(200, [], 'OK');
        $responseData = $requestData->respond($response);

        self::assertInstanceOf(ResponseData::class, $responseData);
        self::assertSame('req_123', $responseData->requestId);
        self::assertSame($response, $responseData->response);
    }

    #[Test]
    public function it_validates_request_id_in_respond(): void
    {
        $config = new ServerConfig(port: 18080);
        $this->server = new Server($config);

        self::assertFalse($this->server->hasPendingResponse());

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('invalid_id', $response);

        $this->server->respond($responseData);

        self::assertFalse($this->server->hasPendingResponse());
    }

    #[Test]
    public function it_handles_invalid_request_id_gracefully(): void
    {
        $config = new ServerConfig(port: 18081);
        $this->server = new Server($config);

        self::assertFalse($this->server->hasPendingResponse());

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('non_existent_id', $response);

        $this->server->respond($responseData);

        self::assertFalse($this->server->hasPendingResponse());
    }

    #[Test]
    public function it_removes_mapping_after_respond(): void
    {
        $config = new ServerConfig(port: 18082);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $queueProperty->setAccessible(true);
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $contextsProperty->setAccessible(true);
        $request = new \Nyholm\Psr7\ServerRequest('GET', '/test');
        $requestData = new RequestData('req_test', $request, 42);

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertArrayHasKey('req_test', $mapping);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertArrayNotHasKey('req_test', $mapping);
    }

    #[Test]
    public function it_has_correct_has_pending_response(): void
    {
        $config = new ServerConfig(port: 18083);
        $this->server = new Server($config);

        self::assertFalse($this->server->hasPendingResponse());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $queueProperty->setAccessible(true);
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $contextsProperty->setAccessible(true);
        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertTrue($this->server->hasPendingResponse());
    }

    #[Test]
    public function it_resets_request_id_counter_on_reset(): void
    {
        $config = new ServerConfig(port: 18084);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);

        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $counterProperty = $rpReflection->getProperty('requestIdCounter');
        $counterProperty->setAccessible(true);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $queueProperty->setAccessible(true);
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $contextsProperty->setAccessible(true);
        $counterProperty->setValue($requestProcessor, 100);
        $contextsProperty->setValue($requestQueue, ['test' => []]);

        $this->server->reset();

        self::assertSame(0, $counterProperty->getValue($requestProcessor));
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }
}
