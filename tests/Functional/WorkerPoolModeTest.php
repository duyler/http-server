<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Fiber;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;
use Throwable;

#[CoversClass(Server::class)]
class WorkerPoolModeTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function setUp(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->findAvailablePort(),
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function set_worker_id_switches_to_worker_pool_mode(): void
    {
        $this->server->setWorkerId(1);

        $this->assertSame(1, $this->server->getWorkerId());
        $this->assertSame('worker_pool', $this->server->getMode()->value);
    }

    #[Test]
    public function set_worker_id_enables_running_state(): void
    {
        $this->server->setWorkerId(5);

        $hasRequest = $this->server->hasRequest();

        $this->assertFalse($hasRequest, 'Worker pool mode without connections should have no requests');
    }

    #[Test]
    public function get_socket_resource_returns_null_without_external(): void
    {
        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function set_external_socket_resource_stores_resource(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->server->setWorkerId(1);
        $this->server->setExternalSocketResource($socket);

        $resource = $this->server->getSocketResource();
        $this->assertInstanceOf(Socket::class, $resource);

        socket_close($socket);
    }

    #[Test]
    public function add_external_connection_with_stream_pair(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $this->server->setWorkerId(1);
        $this->server->addExternalConnection($pair[0], [
            'worker_id' => 1,
            'client_ip' => '127.0.0.1',
        ]);

        $metrics = $this->server->getMetrics();
        $this->assertArrayHasKey('active_connections', $metrics);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    #[Test]
    public function has_request_processes_external_connection(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $this->server->setWorkerId(1);
        $this->server->addExternalConnection($pair[0], [
            'worker_id' => 1,
            'client_ip' => '127.0.0.1',
        ]);

        $request = "GET /test HTTP/1.1\r\nHost: localhost\r\n\r\n";
        fwrite($pair[1], $request);

        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received request from external connection');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('GET', $requestData->request->getMethod());
        $this->assertSame('/test', $requestData->request->getUri()->getPath());

        $response = new Response(200, [], 'WorkerPool OK');
        $this->server->respond(new ResponseData($requestData->id, $response));

        if (is_resource($pair[0])) {
            fclose($pair[0]);
        }
        if (is_resource($pair[1])) {
            fclose($pair[1]);
        }
    }

    #[Test]
    public function register_and_unregister_fiber(): void
    {
        $fiber = new Fiber(function (): void {
            Fiber::suspend();
        });

        $fiber->start();

        $this->server->setWorkerId(1);
        $this->server->registerFiber($fiber);

        $result = $this->server->unregisterFiber($fiber);
        $this->assertTrue($result);
    }

    #[Test]
    public function unregister_unknown_fiber_returns_false(): void
    {
        $fiber = new Fiber(function (): void {});

        $this->server->setWorkerId(1);

        $result = $this->server->unregisterFiber($fiber);
        $this->assertFalse($result);
    }

    #[Test]
    public function set_event_loop_active_and_check(): void
    {
        $this->server->setEventLoopActive(true);
        $this->assertTrue($this->server->isEventLoopActive());

        $this->server->setEventLoopActive(false);
        $this->assertFalse($this->server->isEventLoopActive());
    }

    #[Test]
    public function multiple_workers_have_distinct_ids(): void
    {
        $this->server->setWorkerId(1);
        $this->assertSame(1, $this->server->getWorkerId());

        $this->server->setWorkerId(2);
        $this->assertSame(2, $this->server->getWorkerId());
    }

    #[Test]
    public function respond_cycle_through_external_connection(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($pair);

        $this->server->setWorkerId(1);
        $this->server->addExternalConnection($pair[0], [
            'worker_id' => 1,
            'client_ip' => '10.0.0.1',
        ]);

        $body = '{"action":"test"}';
        $request = "POST /action HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        fwrite($pair[1], $request);

        usleep(150000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received POST request from external connection');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('POST', $requestData->request->getMethod());
        $this->assertSame('/action', $requestData->request->getUri()->getPath());
        $this->assertSame($body, (string) $requestData->request->getBody());

        $response = new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
        $this->server->respond(new ResponseData($requestData->id, $response));

        if (is_resource($pair[0])) {
            fclose($pair[0]);
        }
        if (is_resource($pair[1])) {
            fclose($pair[1]);
        }
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}
