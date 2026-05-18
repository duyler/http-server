<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Dto;

use Psr\Http\Message\ResponseInterface;

/**
 * Data transfer object for HTTP response with request binding
 *
 * Immutable container that binds a PSR-7 response to its
 * originating request via requestId for proper routing.
 *
 * @package Duyler\HttpServer\Dto
 */
final readonly class ResponseData
{
    /**
     * @param string $requestId ID of the request this response belongs to
     * @param ResponseInterface $response PSR-7 response object
     */
    public function __construct(
        public string $requestId,
        public ResponseInterface $response,
    ) {}
}
