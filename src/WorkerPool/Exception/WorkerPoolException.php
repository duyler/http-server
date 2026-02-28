<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Exception;

use Duyler\HttpServer\Exception\HttpServerException;

class WorkerPoolException extends HttpServerException
{
    protected string $errorCode = 'WORKER_POOL_ERROR';
}
