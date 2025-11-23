<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Parser\ResponseWriter;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResponseWriterTest extends TestCase
{
    private ResponseWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new ResponseWriter();
    }

    #[Test]
    public function writes_simple_response(): void
    {
        $response = new Response(200, [], 'Hello World');

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $output);
        $this->assertStringContainsString('Hello World', $output);
    }

    #[Test]
    public function writes_status_code_and_phrase(): void
    {
        $response = new Response(404);

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $output);
    }

    #[Test]
    public function writes_custom_status_phrase(): void
    {
        $response = new Response(200, [], null, '1.1', 'Custom Phrase');

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 200 Custom Phrase', $output);
    }

    #[Test]
    public function writes_headers(): void
    {
        $response = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Custom', 'value');

        $output = $this->writer->write($response);

        $this->assertStringContainsString('Content-Type: application/json', $output);
        $this->assertStringContainsString('X-Custom: value', $output);
    }

    #[Test]
    public function writes_multiple_header_values(): void
    {
        $response = (new Response(200))
            ->withHeader('Set-Cookie', ['cookie1=value1', 'cookie2=value2']);

        $output = $this->writer->write($response);

        $this->assertStringContainsString('Set-Cookie: cookie1=value1', $output);
        $this->assertStringContainsString('Set-Cookie: cookie2=value2', $output);
    }

    #[Test]
    public function writes_response_with_body(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain'], 'Response body');

        $output = $this->writer->write($response);

        $this->assertStringEndsWith("Response body", $output);
    }

    #[Test]
    public function separates_headers_and_body_with_double_crlf(): void
    {
        $response = new Response(200, [], 'Body');

        $output = $this->writer->write($response);

        $this->assertStringContainsString("\r\n\r\nBody", $output);
    }

    #[Test]
    public function writes_empty_body(): void
    {
        $response = new Response(204);

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 204 No Content', $output);
        $this->assertStringEndsWith("\r\n\r\n", $output);
    }

    #[Test]
    public function uses_correct_http_version(): void
    {
        $response = new Response(200, [], null, '1.0');

        $output = $this->writer->write($response);

        $this->assertStringStartsWith('HTTP/1.0', $output);
    }
}
