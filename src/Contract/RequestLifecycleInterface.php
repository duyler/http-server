<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Contract;

use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;

interface RequestLifecycleInterface
{
    public function hasRequest(): bool;

    /**
     * Get next request with unique identifier
     *
     * Returns RequestData containing:
     * - Unique Request ID for response mapping
     * - PSR-7 ServerRequestInterface
     * - Connection identifier
     *
     * @return RequestData|null Request data or null if no requests available
     */
    public function getRequest(): ?RequestData;

    /**
     * Send response with request identifier
     *
     * ResponseData must contain requestId from corresponding RequestData
     * to ensure correct request-response mapping.
     *
     * @param ResponseData $responseData Response data with Request ID and response
     */
    public function respond(ResponseData $responseData): void;

    public function hasPendingResponse(): bool;

    /**
     * Get the request ID of a pending response
     *
     * Returns the first pending request ID if hasPendingResponse() is true.
     * Used by error handlers to send error responses for the current request.
     *
     * @return string|null Request ID or null if no pending response
     */
    public function getPendingRequestId(): ?string;
}
