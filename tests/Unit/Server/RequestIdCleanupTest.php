<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Server;
use Override;
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
    public function testItCleansUpStaleRequests(): void
    {
        $config = new ServerConfig(port: 18200, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('close');

        $oldTimestamp = microtime(true) - 2;

        $property->setValue($requestProcessor, [
            'req_stale' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        self::assertCount(1, $property->getValue($requestProcessor));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($property->getValue($requestProcessor));
    }

    public function testItClosesConnectionOnCleanup(): void
    {
        $config = new ServerConfig(port: 18201, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('close');

        $oldTimestamp = microtime(true) - 2;

        $property->setValue($requestProcessor, [
            'req_stale' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(1);
    }

    public function testItRemovesMappingOnCleanup(): void
    {
        $config = new ServerConfig(port: 18202, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);

        $oldTimestamp = microtime(true) - 2;

        $property->setValue($requestProcessor, [
            'req_old' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
            'req_new' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertCount(2, $property->getValue($requestProcessor));

        $requestProcessor->cleanupStaleRequests(1);

        $mapping = $property->getValue($requestProcessor);
        self::assertCount(1, $mapping);
        self::assertArrayNotHasKey('req_old', $mapping);
        self::assertArrayHasKey('req_new', $mapping);
    }

    public function testItDoesNotCleanupFreshRequests(): void
    {
        $config = new ServerConfig(port: 18203, requestTimeout: 30);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);

        $property->setValue($requestProcessor, [
            'req_fresh' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertCount(1, $property->getValue($requestProcessor));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertCount(1, $property->getValue($requestProcessor));
        self::assertArrayHasKey('req_fresh', $property->getValue($requestProcessor));
    }

    public function testItRunsCleanupViaMethodCall(): void
    {
        $config = new ServerConfig(port: 18204, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isValid')->willReturn(true);

        $oldTimestamp = microtime(true) - 2;

        $property->setValue($requestProcessor, [
            'req_stale' => [
                'connection' => $connection,
                'timestamp' => $oldTimestamp,
            ],
        ]);

        self::assertCount(1, $property->getValue($requestProcessor));

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($property->getValue($requestProcessor));
    }

    public function testItRespectsRequestTimeoutConfig(): void
    {
        $config = new ServerConfig(port: 18205, requestTimeout: 5);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);

        $fourSecondsAgo = microtime(true) - 4;
        $sixSecondsAgo = microtime(true) - 6;

        $property->setValue($requestProcessor, [
            'req_4s' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => $fourSecondsAgo,
            ],
            'req_6s' => [
                'connection' => $connection,
                'timestamp' => $sixSecondsAgo,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(5);

        $mapping = $property->getValue($requestProcessor);
        self::assertCount(1, $mapping);
        self::assertArrayHasKey('req_4s', $mapping);
        self::assertArrayNotHasKey('req_6s', $mapping);
    }

    public function testItHandlesMultipleStaleRequests(): void
    {
        $config = new ServerConfig(port: 18206, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $oldTimestamp = microtime(true) - 2;

        $property->setValue($requestProcessor, [
            'req_stale_1' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => $oldTimestamp,
            ],
            'req_stale_2' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => $oldTimestamp,
            ],
            'req_stale_3' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => $oldTimestamp,
            ],
            'req_fresh' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        self::assertCount(4, $property->getValue($requestProcessor));

        $requestProcessor->cleanupStaleRequests(1);

        $mapping = $property->getValue($requestProcessor);
        self::assertCount(1, $mapping);
        self::assertArrayHasKey('req_fresh', $mapping);
    }

    public function testItHandlesEmptyConnectionsOnCleanup(): void
    {
        $config = new ServerConfig(port: 18207, requestTimeout: 1);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $property->setValue($requestProcessor, []);

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($property->getValue($requestProcessor));
    }

    public function testItCleansUpOnBoundaryTimeout(): void
    {
        $config = new ServerConfig(port: 18208, requestTimeout: 2);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);

        $exactlyTwoSecondsAgo = microtime(true) - 2.01;

        $property->setValue($requestProcessor, [
            'req_boundary' => [
                'connection' => $connection,
                'timestamp' => $exactlyTwoSecondsAgo,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(1);

        self::assertEmpty($property->getValue($requestProcessor));
    }

    public function testItDoesNotCleanupJustUnderTimeout(): void
    {
        $config = new ServerConfig(port: 18209, requestTimeout: 2);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $property = $rpReflection->getProperty('requestConnections');
        $property->setAccessible(true);
        // cleanupStaleRequests is now on requestProcessor

        $connection = $this->createMock(ConnectionInterface::class);

        $justUnderTwoSeconds = microtime(true) - 1.9;

        $property->setValue($requestProcessor, [
            'req_almost' => [
                'connection' => $connection,
                'timestamp' => $justUnderTwoSeconds,
            ],
        ]);

        $requestProcessor->cleanupStaleRequests(2);

        self::assertCount(1, $property->getValue($requestProcessor));
    }
}
