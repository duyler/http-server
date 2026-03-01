<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Exception\ParseException;
use Duyler\HttpServer\Parser\HttpParser;
use Override;
use PHPUnit\Framework\TestCase;

class HttpParserTest extends TestCase
{
    private HttpParser $parser;

    #[Override]
    protected function setUp(): void
    {
        $this->parser = new HttpParser();
    }

    public function testParsesGetRequestLine(): void
    {
        $line = "GET /path HTTP/1.1\r\n";
        $result = $this->parser->parseRequestLine($line);

        $this->assertSame('GET', $result['method']);
        $this->assertSame('/path', $result['uri']);
        $this->assertSame('1.1', $result['version']);
    }

    public function testParsesPostRequestLine(): void
    {
        $line = "POST /api/users HTTP/1.0\r\n";
        $result = $this->parser->parseRequestLine($line);

        $this->assertSame('POST', $result['method']);
        $this->assertSame('/api/users', $result['uri']);
        $this->assertSame('1.0', $result['version']);
    }

    public function testParsesUriWithQueryString(): void
    {
        $line = "GET /search?q=test&page=1 HTTP/1.1\r\n";
        $result = $this->parser->parseRequestLine($line);

        $this->assertSame('/search?q=test&page=1', $result['uri']);
    }

    public function testThrowsExceptionOnInvalidRequestLine(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid request line format');

        $this->parser->parseRequestLine("INVALID\r\n");
    }

    public function testThrowsExceptionOnEmptyRequestLine(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty request line');

        $this->parser->parseRequestLine("\r\n");
    }

    public function testThrowsExceptionOnEmptyUri(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty URI in request line');

        $this->parser->parseRequestLine("GET  HTTP/1.1\r\n");
    }

    public function testThrowsExceptionOnInvalidMethod(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        $this->parser->parseRequestLine("INVALID /path HTTP/1.1\r\n");
    }

    public function testThrowsExceptionOnInvalidVersion(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid HTTP version');

        $this->parser->parseRequestLine("GET /path INVALID\r\n");
    }

    public function testParsesSimpleHeaders(): void
    {
        $headerBlock = "Host: example.com\r\nUser-Agent: Test\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['example.com'], $headers['Host']);
        $this->assertSame(['Test'], $headers['User-Agent']);
    }

    public function testParsesEmptyHeaderBlock(): void
    {
        $this->assertSame([], $this->parser->parseHeaders(''));
    }

    public function testParsesHeadersWithEmptyLines(): void
    {
        $headerBlock = "Host: example.com\r\n\r\nUser-Agent: Test\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['example.com'], $headers['Host']);
        $this->assertSame(['Test'], $headers['User-Agent']);
    }

    public function testParsesMultipleHeaderValues(): void
    {
        $headerBlock = "Accept: text/html\r\nAccept: application/json\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertCount(2, $headers['Accept']);
        $this->assertSame('text/html', $headers['Accept'][0]);
        $this->assertSame('application/json', $headers['Accept'][1]);
    }

    public function testNormalizesHeaderNames(): void
    {
        $headerBlock = "content-type: text/html\r\nCONTENT-LENGTH: 100\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Content-Length', $headers);
    }

    public function testTrimsHeaderValues(): void
    {
        $headerBlock = "Host:   example.com   \r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['example.com'], $headers['Host']);
    }

    public function testThrowsExceptionOnInvalidHeaderFormat(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid header format');

        $this->parser->parseHeaders("InvalidHeader\r\n");
    }

    public function testDetectsCompleteHeaders(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com\r\n\r\nBody";

        $this->assertTrue($this->parser->hasCompleteHeaders($buffer));
    }

    public function testDetectsIncompleteHeaders(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com\r\n";

        $this->assertFalse($this->parser->hasCompleteHeaders($buffer));
    }

    public function testSplitsHeadersAndBody(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com\r\n\r\nBody content";
        [$headers, $body] = $this->parser->splitHeadersAndBody($buffer);

        $this->assertSame("GET / HTTP/1.1\r\nHost: example.com", $headers);
        $this->assertSame('Body content', $body);
    }

    public function testSplitsHeadersAndBodyWithNoSeparator(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com";
        [$headers, $body] = $this->parser->splitHeadersAndBody($buffer);

        $this->assertSame($buffer, $headers);
        $this->assertSame('', $body);
    }

    public function testParsesHeaderContinuation(): void
    {
        $headerBlock = "X-Custom: value1\r\n value2\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['value1 value2'], $headers['X-Custom']);
    }

    public function testParsesHeaderContinuationWithTab(): void
    {
        $headerBlock = "X-Custom: value1\r\n\tvalue2\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['value1 value2'], $headers['X-Custom']);
    }

    public function testParsesHeaderBlockWithContinuationAfterEmptyLine(): void
    {
        $headerBlock = "Host: example.com\r\n \r\nX-Test: value\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['example.com '], $headers['Host']);
        $this->assertSame(['value'], $headers['X-Test']);
    }

    public function testParsesHeadersWithMultipleContinuations(): void
    {
        $headerBlock = "X-Long: line1\r\n line2\r\n\tline3\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['line1 line2 line3'], $headers['X-Long']);
    }

    public function testExtractsContentLength(): void
    {
        $headers = ['Content-Length' => ['42']];

        $length = $this->parser->getContentLength($headers);

        $this->assertSame(42, $length);
    }

    public function testThrowsExceptionOnNegativeContentLength(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid Content-Length value');

        $headers = ['Content-Length' => ['-1']];
        $this->parser->getContentLength($headers);
    }

    public function testReturnsZeroWhenNoContentLength(): void
    {
        $headers = [];

        $length = $this->parser->getContentLength($headers);

        $this->assertSame(0, $length);
    }

    public function testDetectsChunkedEncoding(): void
    {
        $headers = ['Transfer-Encoding' => ['chunked']];

        $this->assertTrue($this->parser->isChunked($headers));
    }

    public function testDetectsNonChunkedEncoding(): void
    {
        $headers = ['Transfer-Encoding' => ['gzip']];

        $this->assertFalse($this->parser->isChunked($headers));
    }

    public function testDetectsNoTransferEncoding(): void
    {
        $headers = [];

        $this->assertFalse($this->parser->isChunked($headers));
    }

    public function testThrowsExceptionOnDuplicateContentLength(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Content-Length');

        $headerBlock = "Content-Length: 100\r\nContent-Length: 200\r\n";
        $this->parser->parseHeaders($headerBlock);
    }

    public function testThrowsExceptionOnDuplicateContentType(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Content-Type');

        $headerBlock = "Content-Type: text/html\r\nContent-Type: application/json\r\n";
        $this->parser->parseHeaders($headerBlock);
    }

    public function testThrowsExceptionOnDuplicateHost(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Host');

        $headerBlock = "Host: example.com\r\nHost: another.com\r\n";
        $this->parser->parseHeaders($headerBlock);
    }

    public function testThrowsExceptionOnDuplicateAuthorization(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Authorization');

        $headerBlock = "Authorization: Bearer token1\r\nAuthorization: Bearer token2\r\n";
        $this->parser->parseHeaders($headerBlock);
    }

    public function testThrowsExceptionOnDuplicateTransferEncoding(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Transfer-Encoding');

        $headerBlock = "Transfer-Encoding: chunked\r\nTransfer-Encoding: gzip\r\n";
        $this->parser->parseHeaders($headerBlock);
    }

    public function testAllowsMultipleCookieHeaders(): void
    {
        $headerBlock = "Cookie: session=abc\r\nCookie: user=john\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertCount(2, $headers['Cookie']);
        $this->assertSame('session=abc', $headers['Cookie'][0]);
        $this->assertSame('user=john', $headers['Cookie'][1]);
    }

    public function testAllowsMultipleAcceptHeaders(): void
    {
        $headerBlock = "Accept: text/html\r\nAccept: application/json\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertCount(2, $headers['Accept']);
    }

    public function testCaseInsensitiveDuplicateDetection(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Content-Length');

        $headerBlock = "content-length: 100\r\nCONTENT-LENGTH: 200\r\n";
        $this->parser->parseHeaders($headerBlock);
    }

    public function testDefaultHeaderCacheLimitIs100(): void
    {
        $parser = new HttpParser();

        $headerBlock = "host: example.com\r\n";
        $headers = $parser->parseHeaders($headerBlock);

        $this->assertArrayHasKey('Host', $headers);
    }

    public function testCustomHeaderCacheLimitWorks(): void
    {
        $parser = new HttpParser(headerCacheLimit: 5);

        for ($i = 0; $i < 10; $i++) {
            $headerBlock = "x-custom-$i: value$i\r\n";
            $result = $parser->parseHeaders($headerBlock);
            $this->assertArrayHasKey("X-Custom-$i", $result);
        }
    }

    public function testHeaderCacheRespectsLimit(): void
    {
        $parser = new HttpParser(headerCacheLimit: 2);

        $parser->parseHeaders("x-header-a: 1\r\n");
        $parser->parseHeaders("x-header-b: 2\r\n");
        $parser->parseHeaders("x-header-c: 3\r\n");
        $headers = $parser->parseHeaders("x-header-a: 4\r\n");

        $this->assertArrayHasKey('X-Header-A', $headers);
    }

    public function testClearCache(): void
    {
        $parser = new HttpParser(headerCacheLimit: 5);

        $parser->parseHeaders("x-header-a: 1\r\n");
        $parser->parseHeaders("x-header-b: 2\r\n");

        $parser->clearCache();

        $headers = $parser->parseHeaders("x-header-a: 3\r\n");
        $this->assertArrayHasKey('X-Header-A', $headers);
    }
}
