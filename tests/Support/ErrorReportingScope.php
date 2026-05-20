<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Support;

trait ErrorReportingScope
{
    protected function withSuppressedErrors(callable $callback): mixed
    {
        $previousLevel = error_reporting(0);
        try {
            return $callback();
        } finally {
            error_reporting($previousLevel);
        }
    }
}
