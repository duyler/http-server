<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Upload\TempFileManager;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestParserTest extends TestCase
{
    private RequestParser $parser;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $this->parser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
    }

    #[Test]
    public function throws_on_empty_request_line(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty request line');

        $rawRequest = "\r\n\r\n";
        $this->parser->parse($rawRequest, '127.0.0.1', 8080);
    }

    #[Test]
    public function parses_simple_get_request(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getUri()->getPath());
        $this->assertSame(['localhost'], $request->getHeader('Host'));
    }

    #[Test]
    public function parses_query_parameters(): void
    {
        $rawRequest = "GET /path?foo=bar&baz=qux HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $queryParams = $request->getQueryParams();
        $this->assertSame('bar', $queryParams['foo']);
        $this->assertSame('qux', $queryParams['baz']);
    }

    #[Test]
    public function parses_cookies(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc123; user=john\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('abc123', $cookies['session']);
        $this->assertSame('john', $cookies['user']);
    }

    #[Test]
    public function parses_form_urlencoded_body(): void
    {
        $body = 'name=John&email=john@example.com';
        $rawRequest = "POST / HTTP/1.1\r\n";
        $rawRequest .= "Host: localhost\r\n";
        $rawRequest .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $rawRequest .= "Content-Length: " . strlen($body) . "\r\n";
        $rawRequest .= "\r\n";
        $rawRequest .= $body;

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $parsedBody = $request->getParsedBody();
        $this->assertSame('John', $parsedBody['name']);
        $this->assertSame('john@example.com', $parsedBody['email']);
    }

    #[Test]
    public function parses_json_body(): void
    {
        $body = json_encode(['name' => 'John', 'age' => 30]);
        $rawRequest = "POST / HTTP/1.1\r\n";
        $rawRequest .= "Host: localhost\r\n";
        $rawRequest .= "Content-Type: application/json\r\n";
        $rawRequest .= "Content-Length: " . strlen($body) . "\r\n";
        $rawRequest .= "\r\n";
        $rawRequest .= $body;

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $parsedBody = $request->getParsedBody();
        $this->assertSame('John', $parsedBody['name']);
        $this->assertSame(30, $parsedBody['age']);
    }

    #[Test]
    public function handles_invalid_json_body(): void
    {
        $body = '{invalid json}';
        $rawRequest = "POST / HTTP/1.1\r\n";
        $rawRequest .= "Host: localhost\r\n";
        $rawRequest .= "Content-Type: application/json\r\n";
        $rawRequest .= "Content-Length: " . strlen($body) . "\r\n";
        $rawRequest .= "\r\n";
        $rawRequest .= $body;

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertNull($request->getParsedBody());
    }

    #[Test]
    public function handles_empty_body(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertNull($request->getParsedBody());
    }

    #[Test]
    public function preserves_server_params(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '192.168.1.100', 54321);

        $serverParams = $request->getServerParams();
        $this->assertSame('192.168.1.100', $serverParams['REMOTE_ADDR']);
        $this->assertSame(54321, $serverParams['REMOTE_PORT']);
        $this->assertSame('GET', $serverParams['REQUEST_METHOD']);
    }

    #[Test]
    public function handles_request_without_host_header(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getUri()->getPath());
    }
}
