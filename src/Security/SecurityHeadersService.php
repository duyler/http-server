<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Security;

use Psr\Http\Message\ResponseInterface;

final readonly class SecurityHeadersService
{
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
            $response = $response->withHeader('X-XSS-Protection', '1; mode=block');
        }

        if ($this->enableReferrerPolicy && false === $response->hasHeader('Referrer-Policy')) {
            $response = $response->withHeader('Referrer-Policy', $this->referrerPolicy);
        }

        if ($this->enablePermissionsPolicy && false === $response->hasHeader('Permissions-Policy')) {
            $response = $response->withHeader('Permissions-Policy', $this->permissionsPolicy);
        }

        if ($this->enableHsts && false === $response->hasHeader('Strict-Transport-Security')) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }
}
