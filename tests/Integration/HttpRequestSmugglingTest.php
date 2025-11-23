<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HttpRequestSmugglingTest extends TestCase
{
    private Server $server;
    private int $port;

    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    #[Test]
    public function rejects_request_with_duplicate_content_length(): void
    {
        $this->server->start();

        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Length: 10\r\n";
        $request .= "Content-Length: 20\r\n";
        $request .= "\r\n";
        $request .= "0123456789";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertFalse($this->server->hasRequest(), 'Server should reject request with duplicate Content-Length');

        fclose($client);
    }

    #[Test]
    public function rejects_request_with_duplicate_host(): void
    {
        $this->server->start();

        $request = "GET / HTTP/1.1\r\n";
        $request .= "Host: example.com\r\n";
        $request .= "Host: attacker.com\r\n";
        $request .= "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertFalse($this->server->hasRequest(), 'Server should reject request with duplicate Host');

        fclose($client);
    }

    #[Test]
    public function rejects_request_with_duplicate_transfer_encoding(): void
    {
        $this->server->start();

        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Transfer-Encoding: chunked\r\n";
        $request .= "Transfer-Encoding: identity\r\n";
        $request .= "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertFalse($this->server->hasRequest(), 'Server should reject request with duplicate Transfer-Encoding');

        fclose($client);
    }

    #[Test]
    public function accepts_request_with_single_valid_headers(): void
    {
        $this->server->start();

        $body = "test body";
        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: text/plain\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "\r\n";
        $request .= $body;

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should accept valid request');

        if ($this->server->hasRequest()) {
            $serverRequest = $this->server->getRequest();
            $this->assertSame('POST', $serverRequest->getMethod());
            $this->assertSame('localhost', $serverRequest->getHeaderLine('Host'));

            $response = new Response(200, [], 'OK');
            $this->server->respond($response);
        }

        fclose($client);
    }

    #[Test]
    public function accepts_request_with_multiple_cookie_headers(): void
    {
        $this->server->start();

        $request = "GET / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Cookie: session=abc\r\n";
        $request .= "Cookie: user=john\r\n";
        $request .= "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should accept request with multiple Cookie headers');

        if ($this->server->hasRequest()) {
            $serverRequest = $this->server->getRequest();
            $cookies = $serverRequest->getCookieParams();
            $this->assertArrayHasKey('session', $cookies);
            $this->assertArrayHasKey('user', $cookies);

            $response = new Response(200, [], 'OK');
            $this->server->respond($response);
        }

        fclose($client);
    }

    /**
     * @return resource
     */
    private function createClient()
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
