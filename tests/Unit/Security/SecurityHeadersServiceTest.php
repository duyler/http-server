<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Security;

use Duyler\HttpServer\Security\SecurityHeadersService;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
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
        $this->assertSame('0', $response->getHeaderLine('X-XSS-Protection'));
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

        $this->assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
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

    #[Test]
    public function csp_header_is_generated_from_directives(): void
    {
        $service = new SecurityHeadersService(
            contentSecurityPolicy: [
                'default-src' => ["'self'"],
                'script-src' => ["'self'", 'cdn.example.com'],
            ],
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertTrue($response->hasHeader('Content-Security-Policy'));
        $csp = $response->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' cdn.example.com", $csp);
    }

    #[Test]
    public function csp_report_only_mode(): void
    {
        $service = new SecurityHeadersService(
            contentSecurityPolicyReportOnly: [
                'default-src' => ["'self'"],
            ],
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertTrue($response->hasHeader('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString("default-src 'self'", $response->getHeaderLine('Content-Security-Policy-Report-Only'));
    }

    #[Test]
    public function csp_nonce_is_generated(): void
    {
        $nonce = $this->service->generateNonce();

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9+\/=]+$/', $nonce);
    }

    #[Test]
    public function csp_nonce_is_unique(): void
    {
        $nonce1 = $this->service->generateNonce();
        $nonce2 = $this->service->generateNonce();

        $this->assertNotSame($nonce1, $nonce2);
    }

    #[Test]
    public function csp_nonce_substitution_in_directives(): void
    {
        $service = new SecurityHeadersService(
            contentSecurityPolicy: [
                'script-src' => ["'self'", "'nonce-{nonce}'"],
            ],
            enableNonce: true,
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $csp = $response->getHeaderLine('Content-Security-Policy');
        $this->assertDoesNotMatchRegularExpression('/\{nonce\}/', $csp);
        $this->assertMatchesRegularExpression("/'nonce-[a-zA-Z0-9+\/=]+'/", $csp);
    }

    #[Test]
    public function csp_not_added_when_null(): void
    {
        $response = new Response(200);
        $response = $this->service->addSecurityHeaders($response);

        $this->assertFalse($response->hasHeader('Content-Security-Policy'));
        $this->assertFalse($response->hasHeader('Content-Security-Policy-Report-Only'));
    }

    #[Test]
    public function permissions_policy_from_array_directives(): void
    {
        $service = new SecurityHeadersService(
            permissionsPolicyDirectives: [
                'geolocation' => [],
                'camera' => ['self'],
                'microphone' => ['https://example.com'],
            ],
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame(
            'geolocation=(), camera=(self), microphone=(https://example.com)',
            $response->getHeaderLine('Permissions-Policy'),
        );
    }

    #[Test]
    public function permissions_policy_empty_directives_blocked(): void
    {
        $service = new SecurityHeadersService(
            permissionsPolicyDirectives: [
                'geolocation' => [],
                'camera' => [],
            ],
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $policy = $response->getHeaderLine('Permissions-Policy');
        $this->assertStringContainsString('geolocation=()', $policy);
        $this->assertStringContainsString('camera=()', $policy);
    }

    #[Test]
    public function permissions_policy_wildcard_allows_all(): void
    {
        $service = new SecurityHeadersService(
            permissionsPolicyDirectives: [
                'fullscreen' => ['*'],
            ],
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertStringContainsString('fullscreen=(*)', $response->getHeaderLine('Permissions-Policy'));
    }

    #[Test]
    public function hsts_configurable_max_age(): void
    {
        $service = new SecurityHeadersService(
            enableHsts: true,
            hstsMaxAge: 86400,
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('max-age=86400', $response->getHeaderLine('Strict-Transport-Security'));
    }

    #[Test]
    public function hsts_include_sub_domains_flag(): void
    {
        $service = new SecurityHeadersService(
            enableHsts: true,
            hstsMaxAge: 31536000,
            hstsIncludeSubDomains: true,
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function hsts_preload_flag(): void
    {
        $service = new SecurityHeadersService(
            enableHsts: true,
            hstsMaxAge: 31536000,
            hstsIncludeSubDomains: true,
            hstsPreload: true,
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame(
            'max-age=31536000; includeSubDomains; preload',
            $response->getHeaderLine('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function hsts_without_flags_is_only_max_age(): void
    {
        $service = new SecurityHeadersService(
            enableHsts: true,
        );
        $response = new Response(200);
        $response = $service->addSecurityHeaders($response);

        $this->assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
    }

    #[Test]
    public function xxss_protection_is_zero(): void
    {
        $response = new Response(200);
        $response = $this->service->addSecurityHeaders($response);

        $this->assertSame('0', $response->getHeaderLine('X-XSS-Protection'));
    }

    #[Test]
    public function build_csp_header_without_nonce(): void
    {
        $result = $this->service->buildCspHeader([
            'default-src' => ["'self'"],
            'img-src' => ["'self'", 'data:'],
        ]);

        $this->assertSame("default-src 'self'; img-src 'self' data:", $result);
    }

    #[Test]
    public function build_permissions_policy_header_mixed(): void
    {
        $result = $this->service->buildPermissionsPolicyHeader([
            'geolocation' => [],
            'camera' => ['self'],
            'fullscreen' => ['*'],
        ]);

        $this->assertSame('geolocation=(), camera=(self), fullscreen=(*)', $result);
    }
}
