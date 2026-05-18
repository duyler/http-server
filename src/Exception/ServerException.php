<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

final class ServerException extends HttpServerException
{
    protected string $errorCode = 'SERVER_ERROR';
}
