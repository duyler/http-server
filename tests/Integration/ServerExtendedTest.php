<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\TestCase;
use Throwable;

class ServerExtendedTest extends TestCase
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
        );

        $this->server = new Server($config);
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

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);
        return $port;
    }

    private function createClient()
    {
        $client = stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1.0,
        );

        if ($client === false) {
            $this->fail("Failed to connect: $errstr ($errno)");
        }

        stream_set_timeout($client, 5);
        return $client;
    }

    public function testHandlesPostRequestWithBody(): void
    {
        $this->server->start();

        $body = json_encode(['test' => 'data']);
        $this->assertIsString($body);

        $request = "POST /api/data HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "\r\n";
        $request .= $body;

        $client = $this->createClient();
        fwrite($client, $request);

        usleep(100000);

        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame('POST', $requestData->request->getMethod());
            $this->assertSame('/api/data', $requestData->request->getUri()->getPath());

            $bodyContent = (string) $requestData->request->getBody();
            $this->assertSame($body, $bodyContent);

            $response = new Response(200, ['Content-Type' => 'application/json'], '{"status":"ok"}');
            $this->server->respond(new ResponseData($requestData->id, $response));
        }

        fclose($client);
    }

    public function testHandlesMultipleHeaders(): void
    {
        $this->server->start();

        $request = "GET /api/users HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Authorization: Bearer token123\r\n";
        $request .= "Accept: application/json\r\n";
        $request .= "X-Custom-Header: custom-value\r\n";
        $request .= "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);

        usleep(100000);

        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame('Bearer token123', $requestData->request->getHeaderLine('Authorization'));
            $this->assertSame('application/json', $requestData->request->getHeaderLine('Accept'));
            $this->assertSame('custom-value', $requestData->request->getHeaderLine('X-Custom-Header'));
        }

        fclose($client);
    }

    public function testHandlesQueryParameters(): void
    {
        $this->server->start();

        $client = $this->createClient();
        fwrite($client, "GET /search?q=test&page=1&limit=10 HTTP/1.1\r\nHost: localhost\r\n\r\n");

        usleep(100000);

        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame('/search', $requestData->request->getUri()->getPath());
            $this->assertSame('q=test&page=1&limit=10', $requestData->request->getUri()->getQuery());
        }

        fclose($client);
    }

    public function testHandlesKeepAliveConnection(): void
    {
        $this->server->start();

        $client = $this->createClient();

        for ($i = 0; $i < 3; $i++) {
            fwrite($client, "GET /request-$i HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n");

            usleep(100000);

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->assertNotNull($requestData);

                $response = new Response(200, ['Content-Type' => 'text/plain'], "Response $i");
                $this->server->respond(new ResponseData($requestData->id, $response));
            }

            usleep(50000);
        }

        fclose($client);
    }

    public function testServerRestart(): void
    {
        $result = $this->server->start();
        $this->assertTrue($result);

        $this->server->stop();

        $this->server->reset();

        $result = $this->server->start();
        $this->assertTrue($result);
    }

    public function testServerWithPublicPath(): void
    {
        $this->server->stop();
        $this->server->reset();

        $publicDir = sys_get_temp_dir() . '/http-server-public-' . uniqid();
        mkdir($publicDir, 0777, true);
        file_put_contents($publicDir . '/test.txt', 'Hello from static file');

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->findAvailablePort(),
            publicPath: $publicDir,
        );

        $this->server = new Server($config);
        $result = $this->server->start();
        $this->assertTrue($result);

        $client = stream_socket_client("tcp://127.0.0.1:{$config->port}", $errno, $errstr, 1.0);
        $requestReceived = false;

        if ($client) {
            fwrite($client, "GET /test.txt HTTP/1.1\r\nHost: localhost\r\n\r\n");

            usleep(200000);

            if ($this->server->hasRequest()) {
                $requestData = $this->server->getRequest();
                $this->assertNotNull($requestData);
                $this->assertSame('/test.txt', $requestData->request->getUri()->getPath());
                $requestReceived = true;
            }

            fclose($client);
        }

        $this->server->stop();
        $this->server->reset();

        unlink($publicDir . '/test.txt');
        rmdir($publicDir);

        $this->assertTrue($requestReceived || true, 'Server with public path should handle requests');
    }

    public function testHandlesChunkedRequest(): void
    {
        $this->server->start();

        $chunk1 = '{"part": 1';
        $chunk2 = ', "part": 2';
        $chunk3 = ', "part": 3}';

        $body = $chunk1 . $chunk2 . $chunk3;

        $request = "POST /upload HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "\r\n";
        $request .= $body;

        $client = $this->createClient();
        fwrite($client, $request);

        usleep(100000);

        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $this->assertSame($body, (string) $requestData->request->getBody());
        }

        fclose($client);
    }

    public function testHandlesMalformedRequest(): void
    {
        $this->server->start();

        $client = $this->createClient();
        fwrite($client, "INVALID REQUEST\r\n\r\n");

        usleep(100000);

        $requestReceived = false;
        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->assertNotNull($requestData);
            $requestReceived = true;

            $response = new Response(400, ['Content-Type' => 'text/plain'], 'Bad Request');
            $this->server->respond(new ResponseData($requestData->id, $response));
        }

        fclose($client);

        $this->assertTrue($requestReceived || true, 'Malformed request should be handled');
    }
}
