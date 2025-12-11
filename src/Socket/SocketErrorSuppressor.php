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
        $previousHandler = set_error_handler(static function (int $errno, string $errstr): bool {
            return true;
        }, E_WARNING);

        try {
            return $callback();
        } finally {
            if ($previousHandler !== null) {
                set_error_handler($previousHandler);
            } else {
                restore_error_handler();
            }
        }
    }
}
