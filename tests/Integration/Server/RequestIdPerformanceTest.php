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
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

class RequestIdPerformanceTest extends TestCase
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
    public function testItHasAcceptableOverhead(): void
    {
        $config = new ServerConfig(port: 18240);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $iterations = 10000;

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $requestProcessor->generateRequestId();
        }
        $time = microtime(true) - $start;

        self::assertLessThan(0.1, $time, 'ID generation should be fast for 10000 iterations');
    }

    public function testItProcesses1000RequestsQuickly(): void
    {
        $config = new ServerConfig(port: 18241);
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

        $iterations = 1000;

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(100);

        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $id = $requestProcessor->generateRequestId();
            $request = new ServerRequest('GET', "/perf-test-$i");
            $requestData = new RequestData($id, $request, $i);

            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);
        }

        $enqueueTime = microtime(true) - $start;

        self::assertLessThan(0.5, $enqueueTime, 'Enqueue 1000 requests should be under 0.5s');

        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $requestData = $this->server->getRequest();
            if ($requestData !== null) {
                $this->server->respond(new ResponseData($requestData->id, new Response(200)));
            }
        }

        $processTime = microtime(true) - $start;

        self::assertLessThan(0.5, $processTime, 'Process 1000 requests should be under 0.5s');
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItHasLowMemoryOverhead(): void
    {
        $config = new ServerConfig(port: 18242);
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

        $memoryBefore = memory_get_usage(true);

        $requestCount = 1000;

        $mapping = [];
        for ($i = 0; $i < $requestCount; $i++) {
            $id = $requestProcessor->generateRequestId();
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
        }

        $contextsProperty->setValue($requestQueue, $mapping);

        $memoryAfter = memory_get_usage(true);
        $memoryDiff = $memoryAfter - $memoryBefore;

        self::assertLessThanOrEqual(2 * 1024 * 1024, $memoryDiff, 'Memory overhead for 1000 requests should be at most 2MB');
    }

    public function testItDoesNotLeakMemory(): void
    {
        $config = new ServerConfig(port: 18243);
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

        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);

        $iterations = 1000;

        for ($i = 0; $i < $iterations; $i++) {
            $id = $requestProcessor->generateRequestId();
            $request = new ServerRequest('GET', "/memory-test-$i");
            $requestData = new RequestData($id, $request, $i);

            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);

            $this->server->getRequest();
            $this->server->respond(new ResponseData($id, new Response(200)));
        }

        gc_collect_cycles();
        $memoryAfter = memory_get_usage(true);
        $memoryDiff = $memoryAfter - $memoryBefore;

        self::assertEmpty($contextsProperty->getValue($requestQueue));
        self::assertFalse($requestQueue->hasRequest());
        self::assertLessThanOrEqual(2 * 1024 * 1024, $memoryDiff, 'Memory overhead should be at most 2MB for 1000 requests');
    }

    public function testItScalesWithConcurrentRequests(): void
    {
        $config = new ServerConfig(port: 18244);
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

        $batchSizes = [100, 500, 1000];
        $times = [];

        foreach ($batchSizes as $batchSize) {
            $start = microtime(true);

            for ($i = 0; $i < $batchSize; $i++) {
                $id = $requestProcessor->generateRequestId();
                $request = new ServerRequest('GET', "/scale-$batchSize-$i");
                $requestData = new RequestData($id, $request, $i);

                $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

                $mapping = $contextsProperty->getValue($requestQueue);
                $mapping[$id] = [
                    'connection' => $connection,
                    'timestamp' => microtime(true),
                ];
                $contextsProperty->setValue($requestQueue, $mapping);
            }

            for ($i = 0; $i < $batchSize; $i++) {
                $requestData = $this->server->getRequest();
                if ($requestData !== null) {
                    $this->server->respond(new ResponseData($requestData->id, new Response(200)));
                }
            }

            $times[$batchSize] = microtime(true) - $start;

            self::assertEmpty($contextsProperty->getValue($requestQueue));
        }

        self::assertGreaterThan(
            $times[100],
            $times[500],
            '500 requests should take more time than 100',
        );

        self::assertGreaterThan(
            $times[500],
            $times[1000],
            '1000 requests should take more time than 500',
        );
    }

    public function testItHandlesLargeRequestBodiesEfficiently(): void
    {
        $config = new ServerConfig(port: 18245);
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
        $connection->method('write')->willReturn(10000);

        $largeBody = str_repeat('x', 10000);

        $start = microtime(true);

        $id = $requestProcessor->generateRequestId();
        $request = new ServerRequest('POST', '/large-body');
        $request = $request->withBody(\Nyholm\Psr7\Stream::create($largeBody));
        $requestData = new RequestData($id, $request, 1);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $contextsProperty->setValue($requestQueue, [
            $id => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $retrievedRequest = $this->server->getRequest();
        self::assertInstanceOf(RequestData::class, $retrievedRequest);

        $response = new Response(200, [], $largeBody);
        $this->server->respond(new ResponseData($retrievedRequest->id, $response));

        $time = microtime(true) - $start;

        self::assertLessThan(0.1, $time, 'Large body handling should be fast');
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItMaintainsPerformanceWithManyHeaders(): void
    {
        $config = new ServerConfig(port: 18246);
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
        $connection->method('write')->willReturn(10000);

        $request = new ServerRequest('GET', '/many-headers');
        for ($i = 0; $i < 50; $i++) {
            $request = $request->withHeader("X-Custom-Header-$i", "value-$i");
        }

        $start = microtime(true);

        $id = $requestProcessor->generateRequestId();
        $requestData = new RequestData($id, $request, 1);

        $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

        $contextsProperty->setValue($requestQueue, [
            $id => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $retrievedRequest = $this->server->getRequest();
        self::assertInstanceOf(RequestData::class, $retrievedRequest);

        $response = new Response(200);
        for ($i = 0; $i < 50; $i++) {
            $response = $response->withHeader("X-Response-Header-$i", "response-value-$i");
        }

        $this->server->respond(new ResponseData($retrievedRequest->id, $response));

        $time = microtime(true) - $start;

        self::assertLessThan(0.05, $time, 'Many headers handling should be fast');
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItBenchmarksRequestIdGeneration(): void
    {
        $config = new ServerConfig(port: 18247);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $iterations = 100000;

        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $requestProcessor->generateRequestId();
        }
        $time = microtime(true) - $start;

        $idsPerSecond = $iterations / $time;

        self::assertGreaterThan(
            100000,
            $idsPerSecond,
            'Should generate at least 100,000 IDs per second',
        );
    }

    public function testItBenchmarksMappingOperations(): void
    {
        $config = new ServerConfig(port: 18248);
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

        $iterations = 10000;

        $start = microtime(true);

        $mapping = [];
        for ($i = 0; $i < $iterations; $i++) {
            $id = $requestProcessor->generateRequestId();
            $mapping[$id] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
        }

        $contextsProperty->setValue($requestQueue, $mapping);
        $insertTime = microtime(true) - $start;

        $start = microtime(true);

        $data = $contextsProperty->getValue($requestQueue);
        $found = 0;
        foreach (array_keys($mapping) as $id) {
            if (array_key_exists($id, $data)) {
                $found++;
            }
        }
        self::assertSame($iterations, $found);

        $lookupTime = microtime(true) - $start;

        $start = microtime(true);

        foreach (array_keys($mapping) as $id) {
            unset($data[$id]);
        }
        $contextsProperty->setValue($requestQueue, $data);

        $deleteTime = microtime(true) - $start;

        self::assertLessThan(0.5, $insertTime, 'Insert 10000 mappings should be under 0.5s');
        self::assertLessThan(0.1, $lookupTime, 'Lookup 10000 mappings should be under 0.1s');
        self::assertLessThan(0.1, $deleteTime, 'Delete 10000 mappings should be under 0.1s');
    }
}
