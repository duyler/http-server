<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Support;

use Duyler\HttpServer\ErrorHandler;

trait ResetsErrorHandler
{
    protected function resetErrorHandler(): void
    {
        ErrorHandler::reset();
    }
}
