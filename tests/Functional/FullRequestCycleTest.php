<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class FullRequestCycleTest extends TestCase
{
    private ?Server $server = null;
    private int $port;

    #[Override]
    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
            enableKeepAlive: true,
        );

        $this->server = new Server($config);
        $this->server->start();
    }

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
    public function get_request_returns_200(): void
    {
        $client = $this->createClient();
        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest());

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('GET', $requestData->request->getMethod());
        $this->assertSame('/', $requestData->request->getUri()->getPath());

        $response = new Response(200, ['Content-Type' => 'text/plain'], 'OK');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $raw);
        $this->assertStringContainsString('OK', $raw);
    }

    #[Test]
    public function post_request_with_json_body(): void
    {
        $body = '{"name":"test","value":42}';
        $request = "POST /api/data HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        $client = $this->createClient();
        fwrite($client, $request);

        usleep(100000);

        $this->assertTrue($this->server->hasRequest());

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('POST', $requestData->request->getMethod());
        $this->assertSame('/api/data', $requestData->request->getUri()->getPath());
        $this->assertSame($body, (string) $requestData->request->getBody());

        $response = new Response(201, ['Content-Type' => 'application/json'], '{"status":"created"}');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('HTTP/1.1 201 Created', $raw);
        $this->assertStringContainsString('created', $raw);
    }

    #[Test]
    public function post_request_with_multipart_form_data(): void
    {
        $boundary = '----TestBoundary12345';
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"field1\"\r\n"
            . "\r\n"
            . "value1\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"field2\"\r\n"
            . "\r\n"
            . "value2\r\n"
            . "--{$boundary}--\r\n";

        $request = "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        $client = $this->createClient();
        fwrite($client, $request);

        usleep(150000);

        $this->assertTrue($this->server->hasRequest());

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('POST', $requestData->request->getMethod());
        $this->assertSame('/upload', $requestData->request->getUri()->getPath());

        $contentType = $requestData->request->getHeaderLine('Content-Type');
        $this->assertStringContainsString('multipart/form-data', $contentType);

        $response = new Response(200, [], 'Upload received');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $raw);
    }

    #[Test]
    public function large_body_request_received(): void
    {
        $body = str_repeat('A', 256 * 1024);

        $request = "POST /large HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        $client = $this->createClient();
        fwrite($client, $request);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(20000);

            if ($this->server->hasRequest()) {
                break;
            }
        }

        $this->assertTrue($this->server->hasRequest(), 'Server should have received large body request within timeout');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);

        $receivedBody = (string) $requestData->request->getBody();
        $this->assertSame(strlen($body), strlen($receivedBody));

        $response = new Response(200, [], 'Large body received');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $raw);
    }

    #[Test]
    public function multiple_sequential_requests_on_same_connection(): void
    {
        $client = $this->createClient();

        for ($i = 0; $i < 3; $i++) {
            fwrite($client, "GET /seq/{$i} HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            usleep(100000);

            $this->assertTrue($this->server->hasRequest(), "Server should have request on iteration {$i}");

            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame("/seq/{$i}", $requestData->request->getUri()->getPath());

            $response = new Response(200, [], "Response {$i}");
            $this->server->respond(new ResponseData($requestData->id, $response));

            usleep(50000);

            $chunk = fread($client, 8192);
            $this->assertStringContainsString("Response {$i}", $chunk);
        }

        fclose($client);
    }

    #[Test]
    public function request_with_custom_headers(): void
    {
        $request = "GET /headers HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-Request-Id: req-12345\r\n"
            . "Authorization: Bearer token-abc\r\n"
            . "Accept: application/json\r\n"
            . "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);

        usleep(100000);

        $this->assertTrue($this->server->hasRequest());

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('req-12345', $requestData->request->getHeaderLine('X-Request-Id'));
        $this->assertSame('Bearer token-abc', $requestData->request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $requestData->request->getHeaderLine('Accept'));

        $response = new Response(200, [], 'Headers OK');
        $this->server->respond(new ResponseData($requestData->id, $response));

        fclose($client);
    }

    #[Test]
    public function request_with_query_parameters(): void
    {
        $client = $this->createClient();
        fwrite($client, "GET /search?q=hello&page=2&limit=50 HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest());

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('/search', $requestData->request->getUri()->getPath());
        $this->assertSame('q=hello&page=2&limit=50', $requestData->request->getUri()->getQuery());

        $response = new Response(200, [], 'Search results');
        $this->server->respond(new ResponseData($requestData->id, $response));

        fclose($client);
    }

    #[Test]
    public function response_contains_security_headers(): void
    {
        $client = $this->createClient();
        fwrite($client, "GET /secure HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        $this->assertTrue($this->server->hasRequest(), 'Server should have received security headers request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);

        $response = new Response(200, ['Content-Type' => 'text/html'], '<h1>Secure</h1>');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('X-Content-Type-Options:', $raw);
    }

    /**
     * @return resource
     */
    private function createClient()
    {
        $client = stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1.0,
        );

        if (false === $client) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

        stream_set_timeout($client, 5);

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
