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
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Throwable;

class RequestIdEdgeCasesTest extends TestCase
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
    public function it_handles_request_timeout(): void
    {
        $config = new ServerConfig(port: 18260, requestTimeout: 1);
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
        $connection->expects($this->once())->method('close');

        $oldTimestamp = microtime(true) - 2;

        $contextsProperty->setValue($requestQueue, [
            'req_timeout' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        self::assertArrayHasKey('req_timeout', $contextsProperty->getValue($requestQueue));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertArrayNotHasKey('req_timeout', $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_connection_close(): void
    {
        $config = new ServerConfig(port: 18261);
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

        $contextsProperty->setValue($requestQueue, [
            'req_closed' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $this->server->respond(new ResponseData('req_closed', $response));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_actor_exception_gracefully(): void
    {
        $config = new ServerConfig(port: 18262);
        $this->server = new Server($config, new NullLogger());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willThrowException(new RuntimeException('Write failed'));

        $contextsProperty->setValue($requestQueue, [
            'req_exception' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(500, [], 'Internal Server Error');

        $this->server->respond(new ResponseData('req_exception', $response));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_duplicate_respond(): void
    {
        $config = new ServerConfig(port: 18263);
        $this->server = new Server($config, new NullLogger());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->expects($this->once())->method('write')->willReturn(100);

        $contextsProperty->setValue($requestQueue, [
            'req_duplicate' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'First Response');
        $this->server->respond(new ResponseData('req_duplicate', $response));

        self::assertEmpty($contextsProperty->getValue($requestQueue));

        $secondResponse = new Response(200, [], 'Second Response');
        $this->server->respond(new ResponseData('req_duplicate', $secondResponse));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_invalid_request_id(): void
    {
        $config = new ServerConfig(port: 18264);
        $this->server = new Server($config, new NullLogger());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $contextsProperty->setValue($requestQueue, [
            'req_valid' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $this->server->respond(new ResponseData('req_nonexistent', $response));

        self::assertCount(1, $contextsProperty->getValue($requestQueue));
        self::assertArrayHasKey('req_valid', $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_cleans_up_after_timeout(): void
    {
        $config = new ServerConfig(port: 18265, requestTimeout: 1);
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
        for ($i = 0; $i < 3; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->expects($this->once())->method('close');
            $connection->method('isValid')->willReturn(false);
            $connections[$i] = $connection;
        }

        for ($i = 3; $i < 5; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connections[$i] = $connection;
        }

        $oldTimestamp = microtime(true) - 5;
        $freshTimestamp = microtime(true);

        $contextsProperty->setValue($requestQueue, [
            'req_old_1' => ['connection' => $connections[0], 'timestamp' => $oldTimestamp],
            'req_old_2' => ['connection' => $connections[1], 'timestamp' => $oldTimestamp],
            'req_old_3' => ['connection' => $connections[2], 'timestamp' => $oldTimestamp],
            'req_fresh_1' => ['connection' => $connections[3], 'timestamp' => $freshTimestamp],
            'req_fresh_2' => ['connection' => $connections[4], 'timestamp' => $freshTimestamp],
        ]);

        self::assertCount(5, $contextsProperty->getValue($requestQueue));

        // cleanupStaleRequests is now on requestProcessor
        $requestProcessor->cleanupStaleRequests(1);

        $remaining = $contextsProperty->getValue($requestQueue);
        self::assertCount(2, $remaining);
        self::assertArrayNotHasKey('req_old_1', $remaining);
        self::assertArrayNotHasKey('req_old_2', $remaining);
        self::assertArrayNotHasKey('req_old_3', $remaining);
        self::assertArrayHasKey('req_fresh_1', $remaining);
        self::assertArrayHasKey('req_fresh_2', $remaining);
    }

    #[Test]
    public function it_handles_empty_request_id(): void
    {
        $config = new ServerConfig(port: 18266);
        $this->server = new Server($config, new NullLogger());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);

        $contextsProperty->setValue($requestQueue, [
            'req_valid' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $this->server->respond(new ResponseData('', $response));

        self::assertCount(1, $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_connection_write_failure(): void
    {
        $config = new ServerConfig(port: 18267);
        $this->server = new Server($config, new NullLogger());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturn(false);
        $connection->expects($this->once())->method('close');

        $contextsProperty->setValue($requestQueue, [
            'req_write_fail' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $this->server->respond(new ResponseData('req_write_fail', $response));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_special_characters_in_response_body(): void
    {
        $config = new ServerConfig(port: 18268);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $writtenData = '';
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int|false {
            $writtenData = $data;
            return strlen($data);
        });

        $contextsProperty->setValue($requestQueue, [
            'req_special' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $specialBody = "Test with special chars: \x00\x01\x02\n\t\r\nUnicode: \u{1F600}";
        $response = new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $specialBody);
        $this->server->respond(new ResponseData('req_special', $response));

        self::assertNotSame('', $writtenData);
        self::assertStringContainsString($specialBody, $writtenData);
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_large_response_headers(): void
    {
        $config = new ServerConfig(port: 18269);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $writtenData = '';
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(false);
        $connection->method('write')->willReturnCallback(function (string $data) use (&$writtenData): int|false {
            $writtenData = $data;
            return strlen($data);
        });

        $contextsProperty->setValue($requestQueue, [
            'req_large_headers' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200);
        for ($i = 0; $i < 100; $i++) {
            $response = $response->withHeader("X-Large-Header-$i", str_repeat('A', 100));
        }

        $this->server->respond(new ResponseData('req_large_headers', $response));

        self::assertNotSame('', $writtenData);
        self::assertGreaterThan(10000, strlen($writtenData));
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_concurrent_cleanup_and_respond(): void
    {
        $config = new ServerConfig(port: 18270, requestTimeout: 1);
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
        for ($i = 0; $i < 10; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(true);
            $connection->method('isKeepAlive')->willReturn(false);
            $connection->method('write')->willReturn(100);
            $connections[$i] = $connection;
        }

        $oldTimestamp = microtime(true) - 5;
        $freshTimestamp = microtime(true);

        $mapping = [];
        for ($i = 0; $i < 5; $i++) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('isValid')->willReturn(false);
            $connection->expects($this->once())->method('close');
            $mapping["req_old_$i"] = [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ];
        }
        for ($i = 5; $i < 10; $i++) {
            $mapping["req_fresh_$i"] = [
                'connection' => $connections[$i],
                'timestamp' => $freshTimestamp,
            ];
        }

        $contextsProperty->setValue($requestQueue, $mapping);

        // cleanupStaleRequests is now on requestProcessor
        $requestProcessor->cleanupStaleRequests(1);

        $remaining = $contextsProperty->getValue($requestQueue);
        self::assertCount(5, $remaining);

        foreach (array_keys($remaining) as $requestId) {
            $this->server->respond(new ResponseData($requestId, new Response(200)));
        }

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_request_without_connection(): void
    {
        $config = new ServerConfig(port: 18271);
        $this->server = new Server($config, new NullLogger());

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $contextsProperty->setValue($requestQueue, []);

        $response = new Response(200, [], 'OK');
        $this->server->respond(new ResponseData('req_orphan', $response));

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_multiple_responses_same_connection(): void
    {
        $config = new ServerConfig(port: 18272);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $writeCount = 0;
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);
        $connection->method('isKeepAlive')->willReturn(true);
        $connection->method('write')->willReturnCallback(function () use (&$writeCount): int|false {
            $writeCount++;
            return 100;
        });

        $connectionId = 42;

        for ($i = 0; $i < 3; $i++) {
            $request = new ServerRequest('GET', "/same-connection-$i");
            $requestData = new RequestData("req_same_$i", $request, $connectionId);
            $requestQueue->enqueue($requestData, ['connection' => $connection, 'timestamp' => microtime(true), 'cors_origin' => null]);

            $mapping = $contextsProperty->getValue($requestQueue);
            $mapping["req_same_$i"] = [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ];
            $contextsProperty->setValue($requestQueue, $mapping);
        }

        self::assertCount(3, $contextsProperty->getValue($requestQueue));

        for ($i = 0; $i < 3; $i++) {
            $requestData = $this->server->getRequest();
            self::assertInstanceOf(RequestData::class, $requestData);
            self::assertSame($connectionId, $requestData->connectionId);

            $this->server->respond(new ResponseData($requestData->id, new Response(200)));
        }

        self::assertSame(3, $writeCount);
        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }
}
