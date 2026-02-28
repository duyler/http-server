<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

class InvalidConfigException extends HttpServerException
{
    protected string $errorCode = 'INVALID_CONFIG';
}
