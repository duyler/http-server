<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Security;

use Duyler\HttpServer\Security\CorsService;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CorsServiceTest extends TestCase
{
    private CorsService $service;

    #[Override]
    protected function setUp(): void
    {
        $this->service = new CorsService(
            allowedOrigins: ['https://example.com', 'https://api.example.com'],
        );
    }

    #[Test]
    public function it_detects_cors_request_with_origin(): void
    {
        $request = new ServerRequest('GET', '/api', ['Origin' => 'https://example.com']);

        $this->assertTrue($this->service->isCorsRequest($request));
    }

    #[Test]
    public function it_rejects_non_cors_request_without_origin(): void
    {
        $request = new ServerRequest('GET', '/api');

        $this->assertFalse($this->service->isCorsRequest($request));
    }

    #[Test]
    public function it_detects_preflight_request(): void
    {
        $request = new ServerRequest('OPTIONS', '/api', ['Origin' => 'https://example.com']);

        $this->assertTrue($this->service->isPreflightRequest($request));
    }

    #[Test]
    public function it_rejects_non_options_as_preflight(): void
    {
        $request = new ServerRequest('GET', '/api', ['Origin' => 'https://example.com']);

        $this->assertFalse($this->service->isPreflightRequest($request));
    }

    #[Test]
    public function it_rejects_options_without_origin_as_preflight(): void
    {
        $request = new ServerRequest('OPTIONS', '/api');

        $this->assertFalse($this->service->isPreflightRequest($request));
    }

    #[Test]
    public function it_allows_whitelisted_origin(): void
    {
        $this->assertTrue($this->service->isOriginAllowed('https://example.com'));
        $this->assertTrue($this->service->isOriginAllowed('https://api.example.com'));
    }

    #[Test]
    public function it_rejects_non_whitelisted_origin(): void
    {
        $this->assertFalse($this->service->isOriginAllowed('https://evil.com'));
    }

    #[Test]
    public function it_allows_all_origins_with_wildcard(): void
    {
        $service = new CorsService(allowedOrigins: ['*']);

        $this->assertTrue($service->isOriginAllowed('https://anything.com'));
        $this->assertTrue($service->isOriginAllowed('https://evil.com'));
    }

    #[Test]
    public function it_creates_preflight_response_with_204(): void
    {
        $response = $this->service->createPreflightResponse('https://example.com');

        $this->assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function it_adds_cors_headers_to_preflight_response(): void
    {
        $response = $this->service->createPreflightResponse('https://example.com');

        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    #[Test]
    public function it_does_not_add_credentials_to_preflight_by_default(): void
    {
        $response = $this->service->createPreflightResponse('https://example.com');

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function it_adds_credentials_to_preflight_when_enabled(): void
    {
        $service = new CorsService(
            allowedOrigins: ['https://example.com'],
            allowCredentials: true,
        );

        $response = $service->createPreflightResponse('https://example.com');

        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function it_uses_custom_max_age_in_preflight(): void
    {
        $service = new CorsService(
            allowedOrigins: ['https://example.com'],
            maxAge: 3600,
        );

        $response = $service->createPreflightResponse('https://example.com');

        $this->assertSame('3600', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    #[Test]
    public function it_adds_cors_headers_to_normal_response(): void
    {
        $response = new Response(200);
        $response = $this->service->addCorsHeaders($response, 'https://example.com');

        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function it_does_not_add_credentials_to_normal_response_by_default(): void
    {
        $response = new Response(200);
        $response = $this->service->addCorsHeaders($response, 'https://example.com');

        $this->assertFalse($response->hasHeader('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function it_adds_credentials_to_normal_response_when_enabled(): void
    {
        $service = new CorsService(
            allowedOrigins: ['https://example.com'],
            allowCredentials: true,
        );

        $response = new Response(200);
        $response = $service->addCorsHeaders($response, 'https://example.com');

        $this->assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    #[Test]
    public function it_does_not_add_expose_headers_by_default(): void
    {
        $response = new Response(200);
        $response = $this->service->addCorsHeaders($response, 'https://example.com');

        $this->assertFalse($response->hasHeader('Access-Control-Expose-Headers'));
    }

    #[Test]
    public function it_adds_expose_headers_when_configured(): void
    {
        $service = new CorsService(
            allowedOrigins: ['https://example.com'],
            exposeHeaders: ['X-Custom-Header', 'X-Request-Id'],
        );

        $response = new Response(200);
        $response = $service->addCorsHeaders($response, 'https://example.com');

        $this->assertSame('X-Custom-Header, X-Request-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[Test]
    public function it_uses_custom_allowed_methods_in_preflight(): void
    {
        $service = new CorsService(
            allowedOrigins: ['https://example.com'],
            allowedMethods: ['GET', 'POST'],
        );

        $response = $service->createPreflightResponse('https://example.com');

        $this->assertSame('GET, POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    #[Test]
    public function it_uses_custom_allowed_headers_in_preflight(): void
    {
        $service = new CorsService(
            allowedOrigins: ['https://example.com'],
            allowedHeaders: ['Content-Type', 'X-Custom'],
        );

        $response = $service->createPreflightResponse('https://example.com');

        $this->assertSame('Content-Type, X-Custom', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[Test]
    public function it_preserves_existing_headers_on_response(): void
    {
        $response = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Custom', 'value');

        $response = $this->service->addCorsHeaders($response, 'https://example.com');

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('value', $response->getHeaderLine('X-Custom'));
        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function it_handles_empty_origin_as_non_cors(): void
    {
        $request = new ServerRequest('GET', '/api', ['Origin' => '']);

        $this->assertFalse($this->service->isCorsRequest($request));
    }

    #[Test]
    public function vary_origin_header_added_to_cors_response(): void
    {
        $response = new Response(200);
        $response = $this->service->addCorsHeaders($response, 'https://example.com');

        $this->assertTrue($response->hasHeader('Vary'));
        $this->assertContains('Origin', $response->getHeader('Vary'));
    }

    #[Test]
    public function vary_headers_added_to_preflight_response(): void
    {
        $response = $this->service->createPreflightResponse('https://example.com');

        $this->assertSame(
            'Origin, Access-Control-Request-Method, Access-Control-Request-Headers',
            $response->getHeaderLine('Vary'),
        );
    }

    #[Test]
    public function vary_origin_appends_to_existing_vary(): void
    {
        $response = (new Response(200))->withHeader('Vary', 'Accept-Encoding');
        $response = $this->service->addCorsHeaders($response, 'https://example.com');

        $this->assertContains('Accept-Encoding', $response->getHeader('Vary'));
        $this->assertContains('Origin', $response->getHeader('Vary'));
    }
}
