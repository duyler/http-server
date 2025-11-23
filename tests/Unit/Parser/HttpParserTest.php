<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Exception\ParseException;
use Duyler\HttpServer\Parser\HttpParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HttpParserTest extends TestCase
{
    private HttpParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HttpParser();
    }

    #[Test]
    public function parses_get_request_line(): void
    {
        $line = "GET /path HTTP/1.1\r\n";
        $result = $this->parser->parseRequestLine($line);

        $this->assertSame('GET', $result['method']);
        $this->assertSame('/path', $result['uri']);
        $this->assertSame('1.1', $result['version']);
    }

    #[Test]
    public function parses_post_request_line(): void
    {
        $line = "POST /api/users HTTP/1.0\r\n";
        $result = $this->parser->parseRequestLine($line);

        $this->assertSame('POST', $result['method']);
        $this->assertSame('/api/users', $result['uri']);
        $this->assertSame('1.0', $result['version']);
    }

    #[Test]
    public function parses_uri_with_query_string(): void
    {
        $line = "GET /search?q=test&page=1 HTTP/1.1\r\n";
        $result = $this->parser->parseRequestLine($line);

        $this->assertSame('/search?q=test&page=1', $result['uri']);
    }

    #[Test]
    public function throws_exception_on_invalid_request_line(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid request line format');

        $this->parser->parseRequestLine("INVALID\r\n");
    }

    #[Test]
    public function throws_exception_on_invalid_method(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        $this->parser->parseRequestLine("INVALID /path HTTP/1.1\r\n");
    }

    #[Test]
    public function throws_exception_on_invalid_version(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid HTTP version');

        $this->parser->parseRequestLine("GET /path INVALID\r\n");
    }

    #[Test]
    public function parses_simple_headers(): void
    {
        $headerBlock = "Host: example.com\r\nUser-Agent: Test\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['example.com'], $headers['Host']);
        $this->assertSame(['Test'], $headers['User-Agent']);
    }

    #[Test]
    public function parses_multiple_header_values(): void
    {
        $headerBlock = "Accept: text/html\r\nAccept: application/json\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertCount(2, $headers['Accept']);
        $this->assertSame('text/html', $headers['Accept'][0]);
        $this->assertSame('application/json', $headers['Accept'][1]);
    }

    #[Test]
    public function normalizes_header_names(): void
    {
        $headerBlock = "content-type: text/html\r\nCONTENT-LENGTH: 100\r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertArrayHasKey('Content-Length', $headers);
    }

    #[Test]
    public function trims_header_values(): void
    {
        $headerBlock = "Host:   example.com   \r\n";
        $headers = $this->parser->parseHeaders($headerBlock);

        $this->assertSame(['example.com'], $headers['Host']);
    }

    #[Test]
    public function throws_exception_on_invalid_header_format(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid header format');

        $this->parser->parseHeaders("InvalidHeader\r\n");
    }

    #[Test]
    public function detects_complete_headers(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com\r\n\r\nBody";

        $this->assertTrue($this->parser->hasCompleteHeaders($buffer));
    }

    #[Test]
    public function detects_incomplete_headers(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com\r\n";

        $this->assertFalse($this->parser->hasCompleteHeaders($buffer));
    }

    #[Test]
    public function splits_headers_and_body(): void
    {
        $buffer = "GET / HTTP/1.1\r\nHost: example.com\r\n\r\nBody content";
        [$headers, $body] = $this->parser->splitHeadersAndBody($buffer);

        $this->assertSame("GET / HTTP/1.1\r\nHost: example.com", $headers);
        $this->assertSame('Body content', $body);
    }

    #[Test]
    public function extracts_content_length(): void
    {
        $headers = ['Content-Length' => ['42']];

        $length = $this->parser->getContentLength($headers);

        $this->assertSame(42, $length);
    }

    #[Test]
    public function returns_zero_when_no_content_length(): void
    {
        $headers = [];

        $length = $this->parser->getContentLength($headers);

        $this->assertSame(0, $length);
    }

    #[Test]
    public function detects_chunked_encoding(): void
    {
        $headers = ['Transfer-Encoding' => ['chunked']];

        $this->assertTrue($this->parser->isChunked($headers));
    }

    #[Test]
    public function detects_non_chunked_encoding(): void
    {
        $headers = ['Transfer-Encoding' => ['gzip']];

        $this->assertFalse($this->parser->isChunked($headers));
    }

    #[Test]
    public function detects_no_transfer_encoding(): void
    {
        $headers = [];

        $this->assertFalse($this->parser->isChunked($headers));
    }
}
