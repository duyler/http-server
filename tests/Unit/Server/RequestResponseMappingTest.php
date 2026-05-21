<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

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

class RequestResponseMappingTest extends TestCase
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
    public function it_creates_mapping_when_request_enqueued(): void
    {
        $config = new ServerConfig(port: 18100);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        self::assertEmpty($contextsProperty->getValue($requestQueue));

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);

        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_test', $request, 42);

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertArrayHasKey('req_test', $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_removes_mapping_after_respond(): void
    {
        $config = new ServerConfig(port: 18101);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(false);
        $connection->expects($this->once())->method('close');

        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_test', $request, 42);

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertArrayHasKey('req_test', $contextsProperty->getValue($requestQueue));

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);

        self::assertArrayNotHasKey('req_test', $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_retrieves_correct_connection_for_response(): void
    {
        $config = new ServerConfig(port: 18102);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection1 = $this->createStub(ConnectionInterface::class);
        $connection1->method('isValid')->willReturn(false);

        $connection2 = $this->createStub(ConnectionInterface::class);
        $connection2->method('isValid')->willReturn(false);

        $contextsProperty->setValue($requestQueue, [
            'req_1' => [
                'connection' => $connection1,
                'timestamp' => microtime(true),
            ],
            'req_2' => [
                'connection' => $connection2,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_2', $response);

        $this->server->respond($responseData);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertArrayHasKey('req_1', $mapping);
        self::assertArrayNotHasKey('req_2', $mapping);
    }

    #[Test]
    public function it_handles_multiple_concurrent_requests(): void
    {
        $config = new ServerConfig(port: 18103);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connections = [];
        for ($i = 0; $i < 5; $i++) {
            $connections[$i] = $this->createStub(ConnectionInterface::class);
            $connections[$i]->method('isValid')->willReturn(false);
        }

        $mapping = [];
        for ($i = 0; $i < 5; $i++) {
            $mapping["req_$i"] = [
                'connection' => $connections[$i],
                'timestamp' => microtime(true),
            ];
        }

        $contextsProperty->setValue($requestQueue, $mapping);

        self::assertCount(5, $contextsProperty->getValue($requestQueue));

        $response = new Response(200, [], 'OK');
        $this->server->respond(new ResponseData('req_2', $response));

        self::assertCount(4, $contextsProperty->getValue($requestQueue));
        self::assertArrayNotHasKey('req_2', $contextsProperty->getValue($requestQueue));

        $this->server->respond(new ResponseData('req_0', $response));
        $this->server->respond(new ResponseData('req_4', $response));

        self::assertCount(2, $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_stores_timestamp_with_mapping(): void
    {
        $config = new ServerConfig(port: 18104);
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
        $timestamp = microtime(true);

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => $timestamp,
            ],
        ]);

        $mapping = $contextsProperty->getValue($requestQueue);

        self::assertArrayHasKey('req_test', $mapping);
        self::assertArrayHasKey('timestamp', $mapping['req_test']);
        self::assertEqualsWithDelta($timestamp, $mapping['req_test']['timestamp'], 0.1);
    }

    #[Test]
    public function it_returns_request_data_from_get_request(): void
    {
        $config = new ServerConfig(port: 18105);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $request = new ServerRequest('POST', '/api/users');
        $requestData = new RequestData('req_42', $request, 100);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(false);

        $contextsProperty->setValue($requestQueue, [
            'req_42' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $result = $this->server->getRequest();

        self::assertInstanceOf(RequestData::class, $result);
        self::assertSame('req_42', $result->id);
        self::assertSame('POST', $result->request->getMethod());
        self::assertSame('/api/users', (string) $result->request->getUri());
        self::assertSame(100, $result->connectionId);
    }

    #[Test]
    public function it_accepts_response_data_in_respond(): void
    {
        $config = new ServerConfig(port: 18106);
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
        $connection->method('isValid')->willReturn(false);

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(201, ['X-Custom' => 'value'], 'Created');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_sends_response_to_correct_connection(): void
    {
        $config = new ServerConfig(port: 18107);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection1 = $this->createMock(ConnectionInterface::class);
        $connection1->method('isValid')->willReturn(true);
        $connection1->expects($this->never())->method('write');

        $connection2 = $this->createMock(ConnectionInterface::class);
        $connection2->method('isValid')->willReturn(true);
        $connection2->expects($this->once())->method('write')->willReturn(100);

        $contextsProperty->setValue($requestQueue, [
            'req_1' => [
                'connection' => $connection1,
                'timestamp' => microtime(true),
            ],
            'req_2' => [
                'connection' => $connection2,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_2', $response);

        $this->server->respond($responseData);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertArrayHasKey('req_1', $mapping);
        self::assertArrayNotHasKey('req_2', $mapping);
    }
}
