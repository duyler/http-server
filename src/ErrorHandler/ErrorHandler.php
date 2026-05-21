<?php

declare(strict_types=1);

namespace Duyler\HttpServer\ErrorHandler;

use Closure;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

final class ErrorHandler implements ErrorHandlerInterface
{
    private bool $registered = false;
    private bool $isShuttingDown = false;
    private bool $shutdownHandlerRegistered = false;
    private mixed $previousErrorHandler = null;
    private mixed $previousExceptionHandler = null;

    /**
     * @param Closure(array{type: int, message: string, file: string, line: int}): void|null $onFatalError
     * @param Closure(int): void|null $onSignal
     * @param Closure(string): void|null $errorOutput Output handler for error messages, defaults to STDERR
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?Closure $onFatalError = null,
        private readonly ?Closure $onSignal = null,
        private readonly ?Closure $errorOutput = null,
    ) {}

    #[Override]
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $this->previousErrorHandler = set_error_handler($this->handleError(...));
        $this->previousExceptionHandler = set_exception_handler($this->handleException(...));

        if (false === $this->shutdownHandlerRegistered) {
            register_shutdown_function($this->handleShutdown(...));
            $this->shutdownHandlerRegistered = true;
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, $this->handleSignal(...));
            pcntl_signal(SIGINT, $this->handleSignal(...));
            pcntl_signal(SIGHUP, $this->handleSignal(...));
            pcntl_async_signals(true);
        }

        $this->logger->info('Error handler registered', [
            'error_handler' => 'yes',
            'exception_handler' => 'yes',
            'shutdown_handler' => 'yes',
            'signal_handler' => function_exists('pcntl_signal') ? 'yes' : 'no',
        ]);
    }

    #[Override]
    public function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
    ): bool {
        if (0 === (error_reporting() & $errno)) {
            return false;
        }

        $errorType = $this->getErrorType($errno);

        $this->logger->error('PHP Error', [
            'type' => $errorType,
            'errno' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);

        if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $this->writeError(sprintf(
                "[FATAL] %s: %s in %s on line %d\n",
                $errorType,
                $errstr,
                $errfile,
                $errline,
            ));
        }

        return false;
    }

    #[Override]
    public function handleException(Throwable $exception): void
    {
        $this->logger->critical('Uncaught exception', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);

        $this->writeError(sprintf(
            "[CRITICAL] Uncaught %s: %s in %s:%d\n%s\n",
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        ));
    }

    #[Override]
    public function handleShutdown(): void
    {
        if ($this->isShuttingDown) {
            return;
        }

        $this->isShuttingDown = true;

        $error = error_get_last();

        // @codeCoverageIgnoreStart
        if (null !== $error && in_array($error['type'], [
            E_ERROR,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_PARSE,
            E_RECOVERABLE_ERROR,
            E_USER_ERROR,
        ], true)) {
            $errorType = $this->getErrorType($error['type']);

            $this->logger->emergency('Fatal error detected on shutdown', [
                'type' => $errorType,
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);

            $this->writeError(sprintf(
                "[FATAL] %s: %s in %s on line %d\n",
                $errorType,
                $error['message'],
                $error['file'],
                $error['line'],
            ));

            flush();

            if (null !== $this->onFatalError) {
                try {
                    ($this->onFatalError)($error);
                } catch (Throwable $e) {
                    $this->logger->error('Error in fatal error callback', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }
        // @codeCoverageIgnoreEnd

        $this->logger->info('Server shutdown normally', [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);
    }

    #[Override]
    public function handleSignal(int $signal): void
    {
        $signalName = $this->getSignalName($signal);

        $this->logger->warning('Received signal', [
            'signal' => $signal,
            'name' => $signalName,
            'memory_usage' => memory_get_usage(true),
        ]);

        $this->writeError(sprintf("[SIGNAL] Received %s (%d)\n", $signalName, $signal));

        if (in_array($signal, [SIGTERM, SIGINT], true)) {
            $this->logger->info('Graceful shutdown initiated');

            if (null !== $this->onSignal) {
                try {
                    ($this->onSignal)($signal);
                } catch (Throwable $e) {
                    $this->logger->error('Error in signal callback', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    #[Override]
    public function reset(): void
    {
        if (false === $this->registered) {
            return;
        }

        $this->registered = false;
        $this->isShuttingDown = false;

        restore_error_handler();
        restore_exception_handler();

        $this->previousErrorHandler = null;
        $this->previousExceptionHandler = null;
    }

    private function writeError(string $message): void
    {
        if (null !== $this->errorOutput) {
            ($this->errorOutput)($message);
            return;
        }

        fwrite(STDERR, $message);
    }

    private function getErrorType(int $errno): string
    {
        return match ($errno) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => "UNKNOWN ($errno)",
        };
    }

    private function getSignalName(int $signal): string
    {
        if (false === defined('SIGTERM')) {
            return "SIGNAL_$signal";
        }

        return match ($signal) {
            SIGTERM => 'SIGTERM',
            SIGINT => 'SIGINT',
            SIGHUP => 'SIGHUP',
            SIGQUIT => 'SIGQUIT',
            SIGKILL => 'SIGKILL',
            SIGUSR1 => 'SIGUSR1',
            SIGUSR2 => 'SIGUSR2',
            default => "SIGNAL_$signal",
        };
    }
}
