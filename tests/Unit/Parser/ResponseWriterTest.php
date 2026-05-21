<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Security\SecurityHeadersService;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResponseWriterTest extends TestCase
{
    private ResponseWriter $writer;

    #[Override]
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

    #[Test]
    public function applies_security_headers_when_service_set(): void
    {
        $securityService = new SecurityHeadersService();
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $output);
        $this->assertStringContainsString('X-Frame-Options: DENY', $output);
        $this->assertStringContainsString('X-XSS-Protection: 0', $output);
        $this->assertStringContainsString('Referrer-Policy: strict-origin-when-cross-origin', $output);
    }

    #[Test]
    public function does_not_apply_security_headers_when_service_not_set(): void
    {
        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringNotContainsString('X-Content-Type-Options', $output);
        $this->assertStringNotContainsString('X-Frame-Options', $output);
        $this->assertStringNotContainsString('X-XSS-Protection', $output);
    }

    #[Test]
    public function does_not_overwrite_existing_security_headers(): void
    {
        $securityService = new SecurityHeadersService();
        $this->writer->setSecurityHeadersService($securityService);

        $response = (new Response(200))->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $output = $this->writer->write($response);

        $this->assertStringContainsString('X-Frame-Options: SAMEORIGIN', $output);
        $this->assertStringNotContainsString('X-Frame-Options: DENY', $output);
    }

    #[Test]
    public function custom_security_headers_from_service(): void
    {
        $securityService = new SecurityHeadersService(
            frameOptions: 'SAMEORIGIN',
            referrerPolicy: 'no-referrer',
        );
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringContainsString('X-Frame-Options: SAMEORIGIN', $output);
        $this->assertStringContainsString('Referrer-Policy: no-referrer', $output);
    }

    #[Test]
    public function hsts_header_when_enabled(): void
    {
        $securityService = new SecurityHeadersService(enableHsts: true);
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringContainsString('Strict-Transport-Security: max-age=31536000', $output);
    }

    #[Test]
    public function no_hsts_header_when_disabled(): void
    {
        $securityService = new SecurityHeadersService(enableHsts: false);
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringNotContainsString('Strict-Transport-Security', $output);
    }

    #[Test]
    public function date_header_present_in_rfc_7231_format(): void
    {
        $response = new Response(200, [], 'OK');
        $output = $this->writer->write($response);

        $this->assertMatchesRegularExpression(
            '/Date: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT/',
            $output,
        );
    }
}
