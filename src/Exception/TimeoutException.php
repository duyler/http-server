<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

final class TimeoutException extends HttpServerException
{
    protected string $errorCode = 'TIMEOUT_ERROR';
}
