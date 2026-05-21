<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Parser;

use Duyler\HttpServer\Security\SecurityHeadersService;
use Psr\Http\Message\ResponseInterface;

final class ResponseWriter
{
    private ?SecurityHeadersService $securityHeadersService = null;

    public function setSecurityHeadersService(SecurityHeadersService $service): void
    {
        $this->securityHeadersService = $service;
    }

    /** @var array<int, string> */
    private const array HTTP_STATUS_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function write(ResponseInterface $response): string
    {
        $response = $this->applySecurityHeaders($response);
        $response = $response->withHeader('Date', gmdate('D, d M Y H:i:s') . ' GMT');

        $parts = [];
        $parts[] = $this->buildStatusLine($response);
        $parts[] = $this->buildHeaders($response);
        $parts[] = "\r\n";
        $parts[] = $this->getBody($response);

        return implode('', $parts);
    }

    private function buildStatusLine(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        if ('' === $reasonPhrase) {
            $reasonPhrase = self::HTTP_STATUS_PHRASES[$statusCode] ?? 'Unknown';
        }

        return sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $statusCode,
            $reasonPhrase,
        );
    }

    private function buildHeaders(ResponseInterface $response): string
    {
        $parts = [];

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $parts[] = sprintf("%s: %s\r\n", $name, $value);
            }
        }

        return implode('', $parts);
    }

    private function getBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $body->rewind();
        return $body->getContents();
    }

    private function applySecurityHeaders(ResponseInterface $response): ResponseInterface
    {
        if (null === $this->securityHeadersService) {
            return $response;
        }

        return $this->securityHeadersService->addSecurityHeaders($response);
    }
}
