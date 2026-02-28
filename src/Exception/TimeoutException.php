<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

class TimeoutException extends HttpServerException
{
    protected string $errorCode = 'TIMEOUT_ERROR';
}
