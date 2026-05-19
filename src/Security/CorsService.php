<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Security;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CorsService
{
    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposeHeaders
     */
    public function __construct(
        private array $allowedOrigins = [],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization'],
        private bool $allowCredentials = false,
        private int $maxAge = 86400,
        private array $exposeHeaders = [],
    ) {}

    public function isCorsRequest(ServerRequestInterface $request): bool
    {
        return '' !== $request->getHeaderLine('Origin');
    }

    public function isPreflightRequest(ServerRequestInterface $request): bool
    {
        return 'OPTIONS' === $request->getMethod() && $this->isCorsRequest($request);
    }

    public function isOriginAllowed(string $origin): bool
    {
        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    public function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ([] !== $this->exposeHeaders) {
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                implode(', ', $this->exposeHeaders),
            );
        }

        return $response->withAddedHeader('Vary', 'Origin');
    }

    public function createPreflightResponse(string $origin): ResponseInterface
    {
        $response = (new Response(204))
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge)
            ->withHeader('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
