<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Exception\ParseException;
use Duyler\HttpServer\Parser\HttpParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class HttpParserEdgeCasesTest extends TestCase
{
    private HttpParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HttpParser();
    }

    #[Test]
    public function parse_headers_throws_on_invalid_format_no_colon_in_slow_path(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid header format');

        $this->parser->parseHeaders("X-Test: value\r\n\tfolded\r\nNoColonHere");
    }

    #[Test]
    public function parse_headers_throws_on_no_colon_after_continuation(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid header format');

        $this->parser->parseHeaders("X-Test: value\r\n continued\r\nBadLineNoColon");
    }

    #[Test]
    public function parse_headers_throws_on_duplicate_singular_header(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Duplicate header not allowed: Content-Type');

        $this->parser->parseHeaders("Content-Type: text/html\r\nContent-Type: application/json");
    }

    #[Test]
    public function parse_headers_handles_folded_headers_with_tab(): void
    {
        $headers = $this->parser->parseHeaders("X-Custom: value1\r\n\tfolded");

        $this->assertArrayHasKey('X-Custom', $headers);
        $this->assertSame(['value1 folded'], $headers['X-Custom']);
    }

    #[Test]
    public function parse_headers_with_continuation_appends_to_value(): void
    {
        $headers = $this->parser->parseHeaders("X-Multi: part1\r\n part2\r\nX-Other: value");

        $this->assertArrayHasKey('X-Multi', $headers);
        $this->assertArrayHasKey('X-Other', $headers);
        $this->assertSame(['part1 part2'], $headers['X-Multi']);
    }

    #[Test]
    public function parse_request_line_throws_on_empty_string(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty request line');

        $this->parser->parseRequestLine('');
    }

    #[Test]
    public function parse_request_line_throws_on_invalid_format(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid request line format');

        $this->parser->parseRequestLine('INVALID');
    }

    #[Test]
    public function parse_request_line_throws_on_empty_uri(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Empty URI in request line');

        $this->parser->parseRequestLine('GET  HTTP/1.1');
    }

    #[Test]
    public function parse_request_line_throws_on_invalid_method(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid HTTP method');

        $this->parser->parseRequestLine('INVALID /path HTTP/1.1');
    }

    #[Test]
    public function parse_request_line_throws_on_invalid_version(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid HTTP version');

        $this->parser->parseRequestLine('GET /path INVALID/1.0');
    }

    #[Test]
    public function parse_request_line_handles_valid_request(): void
    {
        $result = $this->parser->parseRequestLine('GET /path HTTP/1.1');

        $this->assertSame('GET', $result['method']);
        $this->assertSame('/path', $result['uri']);
        $this->assertSame('1.1', $result['version']);
    }

    #[Test]
    public function get_content_length_throws_on_negative(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Invalid Content-Length value');

        $this->parser->getContentLength(['Content-Length' => ['-1']]);
    }

    #[Test]
    public function get_content_length_returns_zero_when_missing(): void
    {
        $result = $this->parser->getContentLength([]);

        $this->assertSame(0, $result);
    }

    #[Test]
    public function is_chunked_returns_true_for_chunked_encoding(): void
    {
        $result = $this->parser->isChunked(['Transfer-Encoding' => ['chunked']]);

        $this->assertTrue($result);
    }

    #[Test]
    public function is_chunked_returns_false_for_no_encoding(): void
    {
        $result = $this->parser->isChunked([]);

        $this->assertFalse($result);
    }

    #[Test]
    public function is_chunked_returns_false_for_non_chunked(): void
    {
        $result = $this->parser->isChunked(['Transfer-Encoding' => ['gzip']]);

        $this->assertFalse($result);
    }

    #[Test]
    public function normalize_header_cache_eviction_works(): void
    {
        $parser = new HttpParser(10);

        for ($i = 0; $i < 15; $i++) {
            $headers = $parser->parseHeaders("X-Header-{$i}: value{$i}");
            $this->assertArrayHasKey("X-Header-{$i}", $headers);
        }
    }

    #[Test]
    public function clear_cache_resets_header_cache(): void
    {
        $this->parser->parseHeaders('X-Test: value');

        $this->parser->clearCache();

        $reflection = new ReflectionProperty(HttpParser::class, 'headerCacheSize');
        $this->assertSame(0, $reflection->getValue($this->parser));
    }

    #[Test]
    public function split_headers_and_body_returns_empty_body_when_no_separator(): void
    {
        [$headers, $body] = $this->parser->splitHeadersAndBody('No separator here');

        $this->assertSame('No separator here', $headers);
        $this->assertSame('', $body);
    }

    #[Test]
    public function has_complete_headers_returns_true_with_double_crlf(): void
    {
        $this->assertTrue($this->parser->hasCompleteHeaders("GET / HTTP/1.1\r\n\r\n"));
    }

    #[Test]
    public function has_complete_headers_returns_false_without_double_crlf(): void
    {
        $this->assertFalse($this->parser->hasCompleteHeaders("GET / HTTP/1.1\r\n"));
    }

    #[Test]
    public function parse_headers_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], $this->parser->parseHeaders(''));
    }

    #[Test]
    public function parse_headers_allows_multiple_non_singular_headers(): void
    {
        $headers = $this->parser->parseHeaders("Accept: text/html\r\nAccept: application/json");

        $this->assertCount(2, $headers['Accept']);
    }

    #[Test]
    public function parse_request_line_handles_case_insensitive_method(): void
    {
        $result = $this->parser->parseRequestLine('post /submit HTTP/1.1');

        $this->assertSame('POST', $result['method']);
    }
}
