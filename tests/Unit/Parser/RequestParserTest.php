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

    #[Test]
    public function parses_cookies_with_urlencoded_value(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: token=hello%40world\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('hello@world', $cookies['token']);
    }

    #[Test]
    public function rejects_cookie_with_invalid_name_containing_separator(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session;id=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session;id', $cookies);
    }

    #[Test]
    public function rejects_cookie_with_invalid_name_containing_parentheses(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: (session)=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('(session)', $cookies);
    }

    #[Test]
    public function rejects_cookie_with_invalid_name_containing_comma(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session,id=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session,id', $cookies);
    }

    #[Test]
    public function rejects_cookie_with_invalid_name_containing_at(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session@id=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session@id', $cookies);
    }

    #[Test]
    public function rejects_cookie_with_empty_name(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: =value\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('', $cookies);
    }

    #[Test]
    public function accepts_cookie_name_with_special_rfc_chars(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session-id_test.user=value123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('value123', $cookies['session-id_test.user']);
    }

    #[Test]
    public function rejects_cookie_value_with_null_byte(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc%00def\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    #[Test]
    public function rejects_cookie_value_with_carriage_return(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc%0Ddef\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    #[Test]
    public function rejects_cookie_value_with_newline(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc%0Adef\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    #[Test]
    public function rejects_cookie_value_with_tab(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc\tdef\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    #[Test]
    public function rejects_cookie_value_exceeding_max_length(): void
    {
        $longValue = str_repeat('a', 4097);
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session={$longValue}\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    #[Test]
    public function accepts_cookie_value_at_max_length(): void
    {
        $maxLengthValue = str_repeat('a', 4096);
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session={$maxLengthValue}\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame($maxLengthValue, $cookies['session']);
    }

    #[Test]
    public function parses_mix_of_valid_and_invalid_cookies(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: valid1=abc; invalid=%00bad; valid2=xyz; valid3=123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('abc', $cookies['valid1']);
        $this->assertArrayNotHasKey('invalid', $cookies);
        $this->assertSame('xyz', $cookies['valid2']);
        $this->assertSame('123', $cookies['valid3']);
    }

    #[Test]
    public function accepts_cookie_value_with_space(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=hello world\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('hello world', $cookies['session']);
    }

    #[Test]
    public function accepts_cookie_value_with_special_chars(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: token=abc!def#xyz\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('abc!def#xyz', $cookies['token']);
    }

    #[Test]
    public function handles_empty_cookie_header(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: \r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame([], $cookies);
    }

    #[Test]
    public function accepts_cookie_with_empty_value(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('', $cookies['session']);
    }
}
