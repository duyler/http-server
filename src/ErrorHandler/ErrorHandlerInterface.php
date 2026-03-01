<?php

declare(strict_types=1);

namespace Duyler\HttpServer\ErrorHandler;

use Throwable;

interface ErrorHandlerInterface
{
    public function register(): void;

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool;

    public function handleException(Throwable $exception): void;

    public function handleShutdown(): void;

    public function handleSignal(int $signal): void;

    public function reset(): void;
}
