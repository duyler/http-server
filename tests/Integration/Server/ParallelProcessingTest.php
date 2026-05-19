<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Fiber;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

class ParallelProcessingTest extends TestCase
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

    public function testItProcessesRequestsInParallel(): void
    {
        $config = new ServerConfig(port: 18200);
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

        $request1 = new ServerRequest('GET', '/slow');
        $request2 = new ServerRequest('GET', '/fast');

        $connection1 = $this->createMock(ConnectionInterface::class);
        $connection1->method('isValid')->willReturn(true);

        $connection2 = $this->createMock(ConnectionInterface::class);
        $connection2->method('isValid')->willReturn(true);

        $requestData1 = new RequestData('req_slow', $request1, 1);
        $requestData2 = new RequestData('req_fast', $request2, 2);

        $requestQueue->enqueue($requestData1, ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $requestQueue->enqueue($requestData2, ['connection' => $connection2, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $responses = [];

        $this->server->respond(new ResponseData('req_fast', new Response(200, [], 'Fast Response')));
        $responses[] = 'fast_done';

        $this->server->respond(new ResponseData('req_slow', new Response(200, [], 'Slow Response')));
        $responses[] = 'slow_done';

        self::assertCount(2, $responses);
        self::assertSame('fast_done', $responses[0]);
        self::assertSame('slow_done', $responses[1]);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItSendsResponsesOutOfOrder(): void
    {
        $config = new ServerConfig(port: 18201);
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

        $connections = [];
        $writeCalls = [];

        for ($i = 1; $i <= 3; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connection->method('isKeepAlive')->willReturn(false);
            $connection
                ->expects($this->once())
                ->method('write')
                ->willReturnCallback(function (string $data) use ($i, &$writeCalls): int|false {
                    $writeCalls[$i] = $data;
                    return strlen($data);
                });
            $connections[$i] = $connection;
        }

        for ($i = 1; $i <= 3; $i++) {
            $request = new ServerRequest('GET', "/request-$i");
            $requestData = new RequestData("req_$i", $request, $i);
            $requestQueue->enqueue($requestData, ['connection' => $connections[$i], 'timestamp' => microtime(true), 'cors_origin' => null]);
        }

        $this->server->respond(new ResponseData('req_2', new Response(200, [], 'Second')));
        $this->server->respond(new ResponseData('req_3', new Response(200, [], 'Third')));
        $this->server->respond(new ResponseData('req_1', new Response(200, [], 'First')));

        self::assertArrayHasKey(2, $writeCalls);
        self::assertArrayHasKey(3, $writeCalls);
        self::assertArrayHasKey(1, $writeCalls);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItHandlesMultipleConcurrentActors(): void
    {
        $config = new ServerConfig(port: 18202);
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

        $actorCount = 10;
        $connections = [];
        $processedOrder = [];

        for ($i = 0; $i < $actorCount; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connection->method('isKeepAlive')->willReturn(false);
            $connection->method('write')->willReturn(100);
            $connections[$i] = $connection;
        }

        for ($i = 0; $i < $actorCount; $i++) {
            $request = new ServerRequest('GET', "/concurrent-$i");
            $requestData = new RequestData("req_$i", $request, $i);
            $requestQueue->enqueue($requestData, ['connection' => $connections[$i], 'timestamp' => microtime(true), 'cors_origin' => null]);
        }

        for ($i = $actorCount - 1; $i >= 0; $i--) {
            $processedOrder[] = $i;
            $response = new Response(200, [], "Response $i");
            $this->server->respond(new ResponseData("req_$i", $response));
        }

        self::assertCount($actorCount, $processedOrder);
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItDoesNotBlockOnSlowRequests(): void
    {
        $config = new ServerConfig(port: 18203);
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

        $slowConnection = $this->createMock(ConnectionInterface::class);
        $slowConnection->method('isValid')->willReturn(true);
        $slowConnection->method('isKeepAlive')->willReturn(false);
        $slowConnection->method('write')->willReturn(100);

        $fastConnection = $this->createMock(ConnectionInterface::class);
        $fastConnection->method('isValid')->willReturn(true);
        $fastConnection->method('isKeepAlive')->willReturn(false);
        $fastConnection->method('write')->willReturn(100);

        $slowRequest = new ServerRequest('GET', '/slow-endpoint');
        $fastRequest = new ServerRequest('GET', '/fast-endpoint');

        $slowRequestData = new RequestData('req_slow', $slowRequest, 1);
        $fastRequestData = new RequestData('req_fast', $fastRequest, 2);

        $requestQueue->enqueue($slowRequestData, ['connection' => $slowConnection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $requestQueue->enqueue($fastRequestData, ['connection' => $fastConnection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $this->server->respond(new ResponseData('req_fast', new Response(200, [], 'Fast Response')));
        $this->server->respond(new ResponseData('req_slow', new Response(200, [], 'Slow Response')));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItCorrectlyMapsResponsesToConnections(): void
    {
        $config = new ServerConfig(port: 18204);
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

        $responseMapping = [];

        $connection1 = $this->createMock(ConnectionInterface::class);
        $connection1->method('isValid')->willReturn(true);
        $connection1->method('isKeepAlive')->willReturn(false);
        $connection1
            ->method('write')
            ->willReturnCallback(function (string $data) use (&$responseMapping): int|false {
                $responseMapping['conn_1'] = $data;
                return strlen($data);
            });

        $connection2 = $this->createMock(ConnectionInterface::class);
        $connection2->method('isValid')->willReturn(true);
        $connection2->method('isKeepAlive')->willReturn(false);
        $connection2
            ->method('write')
            ->willReturnCallback(function (string $data) use (&$responseMapping): int|false {
                $responseMapping['conn_2'] = $data;
                return strlen($data);
            });

        $connection3 = $this->createMock(ConnectionInterface::class);
        $connection3->method('isValid')->willReturn(true);
        $connection3->method('isKeepAlive')->willReturn(false);
        $connection3
            ->method('write')
            ->willReturnCallback(function (string $data) use (&$responseMapping): int|false {
                $responseMapping['conn_3'] = $data;
                return strlen($data);
            });

        $request1 = new ServerRequest('GET', '/user/1');
        $request2 = new ServerRequest('POST', '/user/2');
        $request3 = new ServerRequest('DELETE', '/user/3');

        $requestData1 = new RequestData('req_1', $request1, 1);
        $requestData2 = new RequestData('req_2', $request2, 2);
        $requestData3 = new RequestData('req_3', $request3, 3);

        $requestQueue->enqueue($requestData1, ['connection' => $connection1, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $requestQueue->enqueue($requestData2, ['connection' => $connection2, 'timestamp' => microtime(true), 'cors_origin' => null]);
        $requestQueue->enqueue($requestData3, ['connection' => $connection3, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $this->server->respond(new ResponseData('req_3', new Response(200, [], 'User 3 deleted')));
        $this->server->respond(new ResponseData('req_1', new Response(200, [], 'User 1 data')));
        $this->server->respond(new ResponseData('req_2', new Response(201, [], 'User 2 created')));

        self::assertArrayHasKey('conn_1', $responseMapping);
        self::assertArrayHasKey('conn_2', $responseMapping);
        self::assertArrayHasKey('conn_3', $responseMapping);

        self::assertStringContainsString('User 1 data', $responseMapping['conn_1']);
        self::assertStringContainsString('User 2 created', $responseMapping['conn_2']);
        self::assertStringContainsString('User 3 deleted', $responseMapping['conn_3']);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItHandlesFiberSuspensionCorrectly(): void
    {
        $config = new ServerConfig(port: 18205);
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

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $request = new ServerRequest('GET', '/suspended');
        $requestData = new RequestData('req_suspended', $request, 1);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $suspensionCount = 0;
        $responseSent = false;

        $fiber = new Fiber(function () use (&$suspensionCount, &$responseSent): void {
            Fiber::suspend('first_suspend');
            $suspensionCount++;

            Fiber::suspend('second_suspend');
            $suspensionCount++;

            $response = new Response(200, [], 'After suspension');
            $this->server->respond(new ResponseData('req_suspended', $response));
            $responseSent = true;
        });

        $fiber->start();

        self::assertTrue($fiber->isSuspended());
        self::assertSame(0, $suspensionCount);

        $fiber->resume();
        self::assertTrue($fiber->isSuspended());
        self::assertSame(1, $suspensionCount);

        $fiber->resume();
        self::assertFalse($fiber->isSuspended());
        self::assertSame(2, $suspensionCount);
        self::assertTrue($responseSent);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItProcesses100ConcurrentRequests(): void
    {
        $config = new ServerConfig(port: 18206);
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

        $requestCount = 100;
        $processedCount = 0;

        for ($i = 0; $i < $requestCount; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connection->method('isKeepAlive')->willReturn(false);
            $connection->method('write')->willReturn(100);

            $request = new ServerRequest('GET', "/stress-test-$i");
            $requestData = new RequestData("req_$i", $request, $i);
            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);
        }

        self::assertCount($requestCount, $contextsProperty->getValue($requestQueue));

        for ($i = 0; $i < $requestCount; $i++) {
            $response = new Response(200, [], "Response $i");
            $this->server->respond(new ResponseData("req_$i", $response));
            $processedCount++;
        }

        self::assertSame($requestCount, $processedCount);
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }
}
