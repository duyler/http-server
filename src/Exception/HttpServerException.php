<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

use Exception;
use Throwable;

abstract class HttpServerException extends Exception
{
    protected string $errorCode = 'UNKNOWN_ERROR';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
