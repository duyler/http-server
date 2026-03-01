<?php

declare(strict_types=1);

namespace Duyler\HttpServer\ErrorHandler;

use Override;
use Throwable;

final class TestErrorHandler implements ErrorHandlerInterface
{
    /** @var array<int, array{type: int, message: string, file: string, line: int}> */
    private array $errors = [];

    /** @var array<int, Throwable> */
    private array $exceptions = [];

    private bool $registered = false;

    #[Override]
    public function register(): void
    {
        $this->registered = true;
    }

    #[Override]
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $this->errors[] = [
            'type' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
        ];

        return true;
    }

    #[Override]
    public function handleException(Throwable $exception): void
    {
        $this->exceptions[] = $exception;
    }

    #[Override]
    public function handleShutdown(): void {}

    #[Override]
    public function handleSignal(int $signal): void {}

    #[Override]
    public function reset(): void
    {
        $this->errors = [];
        $this->exceptions = [];
        $this->registered = false;
    }

    /**
     * @return array<int, array{type: int, message: string, file: string, line: int}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<int, Throwable>
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function hasExceptions(): bool
    {
        return count($this->exceptions) > 0;
    }
}
