<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Security;

use Duyler\HttpServer\Security\SecurityHeadersService;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\TestCase;

class SecurityHeadersServiceTest extends TestCase
{
    private SecurityHeadersService $service;

    #[Override]
    protected function setUp(): void
    {
        $this->service = new SecurityHeadersService();
    }

    public function testAddsAllSecurityHeadersByDefault(): void
    {
        $response = new Response(200);
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('1; mode=block', $response->getHeaderLine('X-XSS-Protection'));
        $this->assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        $this->assertSame('geolocation=(), microphone=(), camera=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testAllowsCustomFrameOptions(): void
    {
        $service = new SecurityHeadersService(frameOptions: 'SAMEORIGIN');
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testCanDisableHeaders(): void
    {
        $service = new SecurityHeadersService(enableXFrameOptions: false);
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertFalse($response->hasHeader('X-Frame-Options'));
    }

    public function testDoesNotOverwriteExistingHeaders(): void
    {
        $response = (new Response(200))->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testDoesNotAddHstsByDefault(): void
    {
        $response = new Response(200);
        $response = $this->service->addSecurityHeaders($response);

        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testAddsHstsWhenEnabled(): void
    {
        $service = new SecurityHeadersService(enableHsts: true);
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testCustomReferrerPolicy(): void
    {
        $service = new SecurityHeadersService(referrerPolicy: 'no-referrer');
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testCustomPermissionsPolicy(): void
    {
        $service = new SecurityHeadersService(permissionsPolicy: 'geolocation=()');
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('geolocation=()', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testDisableAllHeaders(): void
    {
        $service = new SecurityHeadersService(
            enableXContentTypeOptions: false,
            enableXFrameOptions: false,
            enableXXSSProtection: false,
            enableReferrerPolicy: false,
            enablePermissionsPolicy: false,
            enableHsts: false,
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertFalse($response->hasHeader('X-Content-Type-Options'));
        $this->assertFalse($response->hasHeader('X-Frame-Options'));
        $this->assertFalse($response->hasHeader('X-XSS-Protection'));
        $this->assertFalse($response->hasHeader('Referrer-Policy'));
        $this->assertFalse($response->hasHeader('Permissions-Policy'));
        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testDoesNotOverwriteXContentTypeOptions(): void
    {
        $response = (new Response(200))->withHeader('X-Content-Type-Options', 'custom');
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('custom', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testDoesNotOverwriteXXSSProtection(): void
    {
        $response = (new Response(200))->withHeader('X-XSS-Protection', '0');
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('0', $response->getHeaderLine('X-XSS-Protection'));
    }

    public function testDoesNotOverwriteReferrerPolicy(): void
    {
        $response = (new Response(200))->withHeader('Referrer-Policy', 'unsafe-url');
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('unsafe-url', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testDoesNotOverwritePermissionsPolicy(): void
    {
        $response = (new Response(200))->withHeader('Permissions-Policy', 'fullscreen=*');
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('fullscreen=*', $response->getHeaderLine('Permissions-Policy'));
    }

    public function testDoesNotOverwriteHsts(): void
    {
        $service = new SecurityHeadersService(enableHsts: true);
        $response = (new Response(200))->withHeader('Strict-Transport-Security', 'max-age=86400');
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('max-age=86400', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testPreservesOtherHeaders(): void
    {
        $response = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Custom', 'value');

        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('value', $response->getHeaderLine('X-Custom'));
    }
}
