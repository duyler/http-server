<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Security;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

class RequestSmugglingTest extends TestCase
{
    private ?Server $server = null;
    private int $port;

    #[Override]
    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();
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
    public function content_length_transfer_encoding_conflict_rejected(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $request = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 5\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . "0\r\n"
            . "\r\n"
            . "GET /smuggled HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $hasRequest = $this->server->hasRequest();
        if ($hasRequest) {
            $requestData = $this->server->getRequest();
            $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
        }

        usleep(50000);

        $this->assertFalse($this->server->hasRequest(), 'Smuggled request should not be parsed');

        fclose($client);
    }

    #[Test]
    public function double_content_length_rejected(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $request = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 5\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n"
            . "helloGET /smuggled HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertFalse($this->server->hasRequest(), 'Request with duplicate Content-Length should be rejected');

        fclose($client);
    }

    #[Test]
    public function chunked_encoding_smuggling_payload_rejected(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $smuggledRequest = "GET /admin HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $chunkBody = "5\r\nhello\r\n0\r\n\r\n" . $smuggledRequest;

        $request = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: " . strlen($chunkBody) . "\r\n"
            . "\r\n"
            . $chunkBody;

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
        }

        usleep(50000);

        $this->assertFalse($this->server->hasRequest(), 'Smuggled request in body should not be treated as separate request');

        fclose($client);
    }

    #[Test]
    public function http_pipelining_injection_not_parsed_as_separate_request(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $request = "GET /first HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "\r\n"
            . "GET /second HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "\r\n";

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        $this->assertTrue($this->server->hasRequest());
        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('/first', $requestData->request->getUri()->getPath());
        $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'First OK')));

        usleep(50000);

        $raw = fread($client, 8192);
        $this->assertStringContainsString('200', $raw);

        fclose($client);
    }

    #[Test]
    public function content_length_with_chunked_encoding_rejected(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();

        $smuggled = "GET /admin HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $request = "POST / HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "Content-Length: " . strlen($smuggled) . "\r\n"
            . "\r\n"
            . "0\r\n"
            . "\r\n"
            . $smuggled;

        $client = $this->createClient();
        fwrite($client, $request);
        usleep(100000);

        if ($this->server->hasRequest()) {
            $requestData = $this->server->getRequest();
            $this->server->respond(new ResponseData($requestData->id, new Response(200, [], 'OK')));
        }

        usleep(50000);

        $this->assertFalse($this->server->hasRequest(), 'Smuggled request after chunked encoding should not be parsed');

        fclose($client);
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
