<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Dto;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Data transfer object for HTTP request with unique identifier
 *
 * Immutable container that holds request metadata including
 * unique ID, PSR-7 request object, and connection identifier.
 */
final readonly class RequestData
{
    /**
     * @param string $id Unique request identifier (format: "req_N")
     * @param ServerRequestInterface $request PSR-7 server request object
     * @param int $connectionId TCP connection identifier
     */
    public function __construct(
        public string $id,
        public ServerRequestInterface $request,
        public int $connectionId,
    ) {}

    /**
     * Create response data associated with this request
     *
     * Convenience method that creates ResponseData using
     * this request's ID as the requestId.
     *
     * @param ResponseInterface $response PSR-7 response object
     * @return ResponseData Response data with request ID binding
     */
    public function respond(ResponseInterface $response): ResponseData
    {
        return new ResponseData($this->id, $response);
    }
}
