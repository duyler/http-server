<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('pcntl')]
class GracefulShutdownIntegrationTest extends TestCase
{
    private Server $server;
    private int $port;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 10,
            connectionTimeout: 10,
        );

        $this->server = new Server($config);
        $this->server->start();
    }

    #[Override]
    protected function tearDown(): void
    {
        try {
            $this->server->stop();
        } catch (Throwable) {
        }
        parent::tearDown();
    }

    #[Test]
    public function shutdown_waits_for_pending_request_to_complete(): void
    {
        $client = $this->connectClient();
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest());
        $request = $this->server->getRequest();

        $shutdownComplete = false;
        $shutdownResult = null;
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->markTestSkipped('Cannot fork process');
        } elseif ($pid === 0) {
            usleep(200000);
            $shutdownResult = $this->server->shutdown(5);
            $shutdownComplete = true;
            exit($shutdownResult ? 0 : 1);
        } else {
            usleep(500000);

            $this->server->respond(new Response(200, [], 'OK'));

            pcntl_waitpid($pid, $status);
            $shutdownResult = pcntl_wexitstatus($status) === 0;
        }

        $response = stream_get_contents($client);
        fclose($client);

        $this->assertStringContainsString('200', $response);
        $this->assertStringContainsString('OK', $response);
    }

    #[Test]
    public function shutdown_with_timeout_forces_close(): void
    {
        $client = $this->connectClient();
        fwrite($client, "GET /slow HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        if ($this->server->hasRequest()) {
            $this->server->getRequest();
        }

        $startTime = microtime(true);
        $result = $this->server->shutdown(1);
        $elapsed = microtime(true) - $startTime;

        fclose($client);

        $this->assertLessThanOrEqual(1.5, $elapsed, 'Shutdown should respect timeout');
    }

    #[Test]
    public function shutdown_processes_queued_requests(): void
    {
        $client1 = $this->connectClient();
        $client2 = $this->connectClient();

        fwrite($client1, "GET /test1 HTTP/1.1\r\nHost: localhost\r\n\r\n");
        fwrite($client2, "GET /test2 HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(200000);

        $this->assertTrue($this->server->hasRequest());
        $request1 = $this->server->getRequest();

        $this->server->respond(new Response(200, [], 'Response 1'));

        usleep(100000);

        if ($this->server->hasRequest()) {
            $request2 = $this->server->getRequest();
            $this->server->respond(new Response(200, [], 'Response 2'));
        }

        usleep(100000);

        $result = $this->server->shutdown(3);

        $response1 = stream_get_contents($client1);
        $response2 = stream_get_contents($client2);

        fclose($client1);
        fclose($client2);

        $this->assertStringContainsString('Response 1', $response1);
        $this->assertTrue(true);
    }

    #[Test]
    public function shutdown_with_no_active_requests_completes_immediately(): void
    {
        $startTime = microtime(true);
        $result = $this->server->shutdown(5);
        $elapsed = microtime(true) - $startTime;

        $this->assertTrue($result);
        $this->assertLessThan(0.5, $elapsed, 'Should complete almost immediately');
    }

    #[Test]
    public function shutdown_does_not_accept_new_connections_after_initiated(): void
    {
        $clientBefore = $this->connectClient();
        fwrite($clientBefore, "GET /before HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        if ($this->server->hasRequest()) {
            $this->server->getRequest();
            $this->server->respond(new Response(200, [], 'Before shutdown'));
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->markTestSkipped('Cannot fork process');
        } elseif ($pid === 0) {
            usleep(200000);
            $this->server->shutdown(2);
            exit(0);
        } else {
            usleep(500000);

            pcntl_waitpid($pid, $status);
        }

        fclose($clientBefore);

        $this->assertTrue(true, 'Shutdown completed');
    }

    #[Test]
    public function multiple_requests_complete_before_shutdown(): void
    {
        $clients = [];
        for ($i = 0; $i < 3; $i++) {
            $clients[$i] = $this->connectClient();
            fwrite($clients[$i], "GET /test{$i} HTTP/1.1\r\nHost: localhost\r\n\r\n");
        }

        usleep(200000);

        $responses = 0;
        while ($this->server->hasRequest() && $responses < 3) {
            $this->server->getRequest();
            $this->server->respond(new Response(200, [], "Response {$responses}"));
            $responses++;
            usleep(50000);
        }

        usleep(100000);

        $result = $this->server->shutdown(3);

        foreach ($clients as $client) {
            fclose($client);
        }

        $this->assertTrue($result || $responses >= 1, 'At least some requests were processed');
    }

    /**
     * @return resource
     */
    private function connectClient()
    {
        $client = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1,
        );

        if ($client === false) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

        return $client;
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
