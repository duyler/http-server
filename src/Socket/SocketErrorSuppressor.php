<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Closure;

trait SocketErrorSuppressor
{
    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    private function suppressSocketWarnings(Closure $callback): mixed
    {
        set_error_handler(static fn(int $errno, string $errstr): bool => true, E_WARNING);

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
