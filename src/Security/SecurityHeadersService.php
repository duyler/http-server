<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Security;

use Psr\Http\Message\ResponseInterface;

final readonly class SecurityHeadersService
{
    /**
     * @param ?array<non-empty-string, list<non-empty-string>> $contentSecurityPolicy
     * @param ?array<non-empty-string, list<non-empty-string>> $contentSecurityPolicyReportOnly
     * @param ?array<non-empty-string, list<non-empty-string>> $permissionsPolicyDirectives
     */
    public function __construct(
        private bool $enableXContentTypeOptions = true,
        private bool $enableXFrameOptions = true,
        private bool $enableXXSSProtection = true,
        private bool $enableReferrerPolicy = true,
        private bool $enablePermissionsPolicy = true,
        private bool $enableHsts = false,
        private string $frameOptions = 'DENY',
        private string $referrerPolicy = 'strict-origin-when-cross-origin',
        private string $permissionsPolicy = 'geolocation=(), microphone=(), camera=()',
        private ?array $contentSecurityPolicy = null,
        private ?array $contentSecurityPolicyReportOnly = null,
        private bool $enableNonce = false,
        private ?array $permissionsPolicyDirectives = null,
        private int $hstsMaxAge = 31536000,
        private bool $hstsIncludeSubDomains = false,
        private bool $hstsPreload = false,
    ) {}

    public function addSecurityHeaders(ResponseInterface $response): ResponseInterface
    {
        if ($this->enableXContentTypeOptions && false === $response->hasHeader('X-Content-Type-Options')) {
            $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        }

        if ($this->enableXFrameOptions && false === $response->hasHeader('X-Frame-Options')) {
            $response = $response->withHeader('X-Frame-Options', $this->frameOptions);
        }

        if ($this->enableXXSSProtection && false === $response->hasHeader('X-XSS-Protection')) {
            $response = $response->withHeader('X-XSS-Protection', '0');
        }

        if ($this->enableReferrerPolicy && false === $response->hasHeader('Referrer-Policy')) {
            $response = $response->withHeader('Referrer-Policy', $this->referrerPolicy);
        }

        if ($this->enablePermissionsPolicy && false === $response->hasHeader('Permissions-Policy')) {
            $policy = null !== $this->permissionsPolicyDirectives
                ? $this->buildPermissionsPolicyHeader($this->permissionsPolicyDirectives)
                : $this->permissionsPolicy;
            $response = $response->withHeader('Permissions-Policy', $policy);
        }

        if ($this->enableHsts && false === $response->hasHeader('Strict-Transport-Security')) {
            $response = $response->withHeader('Strict-Transport-Security', $this->buildHstsHeader());
        }

        if (null !== $this->contentSecurityPolicy && false === $response->hasHeader('Content-Security-Policy')) {
            $nonce = $this->enableNonce ? $this->generateNonce() : null;
            $response = $response->withHeader(
                'Content-Security-Policy',
                $this->buildCspHeader($this->contentSecurityPolicy, $nonce),
            );
        }

        if (null !== $this->contentSecurityPolicyReportOnly && false === $response->hasHeader('Content-Security-Policy-Report-Only')) {
            $nonce = $this->enableNonce ? $this->generateNonce() : null;
            $response = $response->withHeader(
                'Content-Security-Policy-Report-Only',
                $this->buildCspHeader($this->contentSecurityPolicyReportOnly, $nonce),
            );
        }

        return $response;
    }

    public function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $directives
     */
    public function buildCspHeader(array $directives, ?string $nonce = null): string
    {
        $parts = [];

        foreach ($directives as $directive => $values) {
            $processedValues = [];

            foreach ($values as $value) {
                $processedValues[] = null !== $nonce ? str_replace('{nonce}', $nonce, $value) : $value;
            }

            $parts[] = $directive . ' ' . implode(' ', $processedValues);
        }

        return implode('; ', $parts);
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $directives
     */
    public function buildPermissionsPolicyHeader(array $directives): string
    {
        $parts = [];

        foreach ($directives as $directive => $values) {
            if ([] === $values) {
                $parts[] = $directive . '=()';
                continue;
            }

            if (in_array('*', $values, true)) {
                $parts[] = $directive . '=(*)';
                continue;
            }

            $parts[] = $directive . '=(' . implode(' ', $values) . ')';
        }

        return implode(', ', $parts);
    }

    private function buildHstsHeader(): string
    {
        $hsts = 'max-age=' . $this->hstsMaxAge;

        if ($this->hstsIncludeSubDomains) {
            $hsts .= '; includeSubDomains';
        }

        if ($this->hstsPreload) {
            $hsts .= '; preload';
        }

        return $hsts;
    }
}
