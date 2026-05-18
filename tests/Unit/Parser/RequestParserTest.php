<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Upload\TempFileManager;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
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

    public function testThrowsOnEmptyRequestLine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty request line');

        $rawRequest = "\r\n\r\n";
        $this->parser->parse($rawRequest, '127.0.0.1', 8080);
    }

    public function testParsesSimpleGetRequest(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getUri()->getPath());
        $this->assertSame(['localhost'], $request->getHeader('Host'));
    }

    public function testParsesQueryParameters(): void
    {
        $rawRequest = "GET /path?foo=bar&baz=qux HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $queryParams = $request->getQueryParams();
        $this->assertSame('bar', $queryParams['foo']);
        $this->assertSame('qux', $queryParams['baz']);
    }

    public function testParsesCookies(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc123; user=john\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('abc123', $cookies['session']);
        $this->assertSame('john', $cookies['user']);
    }

    public function testParsesFormUrlencodedBody(): void
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

    public function testParsesJsonBody(): void
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

    public function testHandlesInvalidJsonBody(): void
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

    public function testHandlesEmptyBody(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertNull($request->getParsedBody());
    }

    public function testPreservesServerParams(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '192.168.1.100', 54321);

        $serverParams = $request->getServerParams();
        $this->assertSame('192.168.1.100', $serverParams['REMOTE_ADDR']);
        $this->assertSame(54321, $serverParams['REMOTE_PORT']);
        $this->assertSame('GET', $serverParams['REQUEST_METHOD']);
    }

    public function testHandlesRequestWithoutHostHeader(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/', $request->getUri()->getPath());
    }

    public function testParsesCookiesWithUrlencodedValue(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: token=hello%40world\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('hello@world', $cookies['token']);
    }

    public function testRejectsCookieWithInvalidNameContainingSeparator(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session;id=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session;id', $cookies);
    }

    public function testRejectsCookieWithInvalidNameContainingParentheses(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: (session)=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('(session)', $cookies);
    }

    public function testRejectsCookieWithInvalidNameContainingComma(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session,id=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session,id', $cookies);
    }

    public function testRejectsCookieWithInvalidNameContainingAt(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session@id=abc123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session@id', $cookies);
    }

    public function testRejectsCookieWithEmptyName(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: =value\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('', $cookies);
    }

    public function testAcceptsCookieNameWithSpecialRfcChars(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session-id_test.user=value123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('value123', $cookies['session-id_test.user']);
    }

    public function testRejectsCookieValueWithNullByte(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc%00def\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    public function testRejectsCookieValueWithCarriageReturn(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc%0Ddef\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    public function testRejectsCookieValueWithNewline(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc%0Adef\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    public function testRejectsCookieValueWithTab(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc\tdef\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    public function testRejectsCookieValueExceedingMaxLength(): void
    {
        $longValue = str_repeat('a', 4097);
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session={$longValue}\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertArrayNotHasKey('session', $cookies);
    }

    public function testAcceptsCookieValueAtMaxLength(): void
    {
        $maxLengthValue = str_repeat('a', 4096);
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session={$maxLengthValue}\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame($maxLengthValue, $cookies['session']);
    }

    public function testParsesMixOfValidAndInvalidCookies(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: valid1=abc; invalid=%00bad; valid2=xyz; valid3=123\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('abc', $cookies['valid1']);
        $this->assertArrayNotHasKey('invalid', $cookies);
        $this->assertSame('xyz', $cookies['valid2']);
        $this->assertSame('123', $cookies['valid3']);
    }

    public function testAcceptsCookieValueWithSpace(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=hello world\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('hello world', $cookies['session']);
    }

    public function testAcceptsCookieValueWithSpecialChars(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: token=abc!def#xyz\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('abc!def#xyz', $cookies['token']);
    }

    public function testHandlesEmptyCookieHeader(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: \r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame([], $cookies);
    }

    public function testAcceptsCookieWithEmptyValue(): void
    {
        $rawRequest = "GET / HTTP/1.1\r\nHost: localhost\r\nCookie: session=\r\n\r\n";

        $request = $this->parser->parse($rawRequest, '127.0.0.1', 8080);

        $cookies = $request->getCookieParams();
        $this->assertSame('', $cookies['session']);
    }
}
