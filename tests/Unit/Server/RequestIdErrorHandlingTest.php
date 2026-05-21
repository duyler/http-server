<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
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
    #[Test]
    public function it_handles_invalid_request_id_gracefully(): void
    {
        $config = new ServerConfig(port: 18300);
        $this->server = new Server($config);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('non_existent_id', $response);

        $this->server->respond($responseData);

        self::assertFalse($this->server->hasPendingResponse());
    }

    #[Test]
    public function it_does_not_throw_for_invalid_request_id(): void
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

    #[Test]
    public function it_handles_duplicate_respond_gracefully(): void
    {
        $config = new ServerConfig(port: 18302);
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

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_test', $response);

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));

        $this->server->respond($responseData);

        self::assertEmpty($contextsProperty->getValue($requestQueue));
    }

    #[Test]
    public function it_handles_closed_connection_in_respond(): void
    {
        $config = new ServerConfig(port: 18303);
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

    #[Test]
    public function it_validates_connection_before_send(): void
    {
        $config = new ServerConfig(port: 18304);
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

    #[Test]
    public function it_returns_early_for_invalid_request_id(): void
    {
        $config = new ServerConfig(port: 18305);
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

    #[Test]
    public function it_logs_warning_for_invalid_request_id(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
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

    #[Test]
    public function it_logs_valid_request_ids_on_error(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
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
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $queueProperty = $rpReflection->getProperty('requestQueue');
        $requestQueue = $queueProperty->getValue($requestProcessor);
        $rqReflection = new ReflectionClass($requestQueue);
        $contextsProperty = $rqReflection->getProperty('contexts');
        $contextsProperty->setValue($requestQueue, [
            'req_1' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
            'req_2' => [
                'connection' => $this->createStub(ConnectionInterface::class),
                'timestamp' => microtime(true),
            ],
        ]);

        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('invalid_id', $response);

        $this->server->respond($responseData);
    }

    #[Test]
    public function it_handles_empty_request_id(): void
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

    #[Test]
    public function it_handles_special_characters_in_request_id(): void
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

    #[Test]
    public function it_handles_very_long_request_id(): void
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

    #[Test]
    public function it_maintains_state_after_multiple_invalid_attempts(): void
    {
        $config = new ServerConfig(port: 18310);
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
