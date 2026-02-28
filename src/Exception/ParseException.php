<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

class ParseException extends HttpServerException
{
    protected string $errorCode = 'PARSE_ERROR';
}
