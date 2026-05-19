<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

class RequestIdErrorHandlingTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            $this->server->stop();
            $this->server->reset();
        }
        parent::tearDown();
    }
    public function testItHandlesInvalidRequestIdGracefully(): void
    {
        $config = new ServerConfig(port: 18300);
        $this->server = new Server($config);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('non_existent_id', $response);

        $this->server->respond($responseData);

        self::assertFalse($this->server->hasPendingResponse());
    }

    public function testItDoesNotThrowForInvalidRequestId(): void
    {
        $config = new ServerConfig(port: 18301);
        $this->server = new Server($config);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('invalid_id_xyz', $response);

        $exception = null;
        try {
            $this->server->respond($responseData);
        } catch (Throwable $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }

    public function testItHandlesDuplicateRespondGracefully(): void
    {
        $config = new ServerConfig(port: 18302);
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
        $connection->method('isValid')->willReturn(false);

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItHandlesClosedConnectionInRespond(): void
    {
        $config = new ServerConfig(port: 18303);
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
        $connection->method('isValid')->willReturn(false);
        $connection->expects($this->once())->method('close');

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    public function testItValidatesConnectionBeforeSend(): void
    {
        $config = new ServerConfig(port: 18304);
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
        $connection->expects($this->once())->method('isValid')->willReturn(false);
        $connection->expects($this->never())->method('write');

        $contextsProperty->setValue($requestQueue, [
            'req_test' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);
    }

    public function testItReturnsEarlyForInvalidRequestId(): void
    {
        $config = new ServerConfig(port: 18305);
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
        $connection->expects($this->never())->method('isValid');

        $contextsProperty->setValue($requestQueue, [
            'req_valid' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_invalid', $response);

        $this->server->respond($responseData);

        self::assertCount(1, $contextsProperty->getValue($requestQueue));
    }

    public function testItLogsWarningForInvalidRequestId(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('invalid request ID'),
                $this->callback(fn(array $context) => isset($context['request_id']) && $context['request_id'] === 'invalid_id'),
            );

        $config = new ServerConfig(port: 18306);
        $this->server = new Server($config, $logger);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('invalid_id', $response);

        $this->server->respond($responseData);
    }

    public function testItLogsValidRequestIdsOnError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        assert($logger instanceof LoggerInterface);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('invalid request ID'),
                $this->callback(fn(array $context) => isset($context['request_id'])),
            );

        $config = new ServerConfig(port: 18311);
        $this->server = new Server($config, $logger);

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
            'req_1' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
            'req_2' => [
                'connection' => $this->createMock(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('invalid_id', $response);

        $this->server->respond($responseData);
    }

    public function testItHandlesEmptyRequestId(): void
    {
        $config = new ServerConfig(port: 18307);
        $this->server = new Server($config);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('', $response);

        $exception = null;
        try {
            $this->server->respond($responseData);
        } catch (Throwable $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }

    public function testItHandlesSpecialCharactersInRequestId(): void
    {
        $config = new ServerConfig(port: 18308);
        $this->server = new Server($config);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData("req_<script>alert('xss')</script>", $response);

        $exception = null;
        try {
            $this->server->respond($responseData);
        } catch (Throwable $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }

    public function testItHandlesVeryLongRequestId(): void
    {
        $config = new ServerConfig(port: 18309);
        $this->server = new Server($config);

        $longId = str_repeat('a', 10000);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData($longId, $response);

        $exception = null;
        try {
            $this->server->respond($responseData);
        } catch (Throwable $e) {
            $exception = $e;
        }

        self::assertNull($exception);
    }

    public function testItMaintainsStateAfterMultipleInvalidAttempts(): void
    {
        $config = new ServerConfig(port: 18310);
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
        $connection->method('isValid')->willReturn(false);

        $contextsProperty->setValue($requestQueue, [
            'req_valid' => [
                'connection' => $connection,
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');

        $this->server->respond(new ResponseData('invalid_1', $response));
        $this->server->respond(new ResponseData('invalid_2', $response));
        $this->server->respond(new ResponseData('invalid_3', $response));

        self::assertCount(1, $contextsProperty->getValue($requestQueue));
        self::assertArrayHasKey('req_valid', $contextsProperty->getValue($requestQueue));
    }
}
