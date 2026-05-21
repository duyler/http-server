<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

class RequestIdCleanupTest extends TestCase
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
    public function it_cleans_up_stale_requests(): void
    {
        $config = new ServerConfig(port: 18200, requestTimeout: 1);
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
            'req_stale' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        self::assertCount(1, $contextsProperty->getValue($requestQueue));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_closes_connection_on_cleanup(): void
    {
        $config = new ServerConfig(port: 18201, requestTimeout: 1);
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
            'req_stale' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(1);
    }

    #[Test]
    public function it_removes_mapping_on_cleanup(): void
    {
        $config = new ServerConfig(port: 18202, requestTimeout: 1);
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

        $oldTimestamp = microtime(true) - 2;

        $contextsProperty->setValue($requestQueue, [
            'req_old' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
            'req_new' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertCount(2, $contextsProperty->getValue($requestQueue));

        $requestProcessor->cleanupStaleRequests(1);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertCount(1, $mapping);
        self::assertArrayNotHasKey('req_old', $mapping);
        self::assertArrayHasKey('req_new', $mapping);
    }

    #[Test]
    public function it_does_not_cleanup_fresh_requests(): void
    {
        $config = new ServerConfig(port: 18203, requestTimeout: 30);
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

        $contextsProperty->setValue($requestQueue, [
            'req_fresh' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertCount(1, $contextsProperty->getValue($requestQueue));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertCount(1, $contextsProperty->getValue($requestQueue));
        self::assertArrayHasKey('req_fresh', $contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_runs_cleanup_via_method_call(): void
    {
        $config = new ServerConfig(port: 18204, requestTimeout: 1);
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

        $oldTimestamp = microtime(true) - 2;

        $contextsProperty->setValue($requestQueue, [
            'req_stale' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        self::assertCount(1, $contextsProperty->getValue($requestQueue));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_respects_request_timeout_config(): void
    {
        $config = new ServerConfig(port: 18205, requestTimeout: 5);
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

        $fourSecondsAgo = microtime(true) - 4;
        $sixSecondsAgo = microtime(true) - 6;

        $contextsProperty->setValue($requestQueue, [
            'req_4s' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => $fourSecondsAgo,
            ],
            'req_6s' => [
                'connection' => $connection,
                'timestamp' => $sixSecondsAgo,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(5);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertCount(1, $mapping);
        self::assertArrayHasKey('req_4s', $mapping);
        self::assertArrayNotHasKey('req_6s', $mapping);
    }

    #[Test]
    public function it_handles_multiple_stale_requests(): void
    {
        $config = new ServerConfig(port: 18206, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');

        $oldTimestamp = microtime(true) - 2;

        $contextsProperty->setValue($requestQueue, [
            'req_stale_1' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => $oldTimestamp,
            ],
            'req_stale_2' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => $oldTimestamp,
            ],
            'req_stale_3' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => $oldTimestamp,
            ],
            'req_fresh' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertCount(4, $contextsProperty->getValue($requestQueue));

        $requestProcessor->cleanupStaleRequests(1);

        $mapping = $contextsProperty->getValue($requestQueue);
        self::assertCount(1, $mapping);
        self::assertArrayHasKey('req_fresh', $mapping);
    }

    #[Test]
    public function it_handles_empty_connections_on_cleanup(): void
    {
        $config = new ServerConfig(port: 18207, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');

        $contextsProperty->setValue($requestQueue, []);

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_cleans_up_on_boundary_timeout(): void
    {
        $config = new ServerConfig(port: 18208, requestTimeout: 2);
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

        $exactlyTwoSecondsAgo = microtime(true) - 2.01;

        $contextsProperty->setValue($requestQueue, [
            'req_boundary' => [
                'connection' => $connection,
                'timestamp' => $exactlyTwoSecondsAgo,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_does_not_cleanup_just_under_timeout(): void
    {
        $config = new ServerConfig(port: 18209, requestTimeout: 2);
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

        $justUnderTwoSeconds = microtime(true) - 1.9;

        $contextsProperty->setValue($requestQueue, [
            'req_almost' => [
                'connection' => $connection,
                'timestamp' => $justUnderTwoSeconds,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(2);

        self::assertCount(1, $contextsProperty->getValue($requestQueue));
    }
}
