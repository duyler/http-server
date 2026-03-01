<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

final class ParseException extends HttpServerException
{
    protected string $errorCode = 'PARSE_ERROR';
}
