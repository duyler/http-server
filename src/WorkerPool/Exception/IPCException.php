<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Exception;

use Duyler\HttpServer\Exception\HttpServerException;

class IPCException extends HttpServerException
{
    protected string $errorCode = 'IPC_ERROR';
}
