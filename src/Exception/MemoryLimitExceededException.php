<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

final class MemoryLimitExceededException extends HttpServerException
{
    protected string $errorCode = 'MEMORY_LIMIT_EXCEEDED';
}
