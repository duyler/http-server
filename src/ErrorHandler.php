<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

class ErrorHandler
{
    private static ?LoggerInterface $logger = null;
    private static bool $registered = false;
    private static bool $isShuttingDown = false;
    private static ?Closure $onFatalError = null;
    private static ?Closure $onSignal = null;

    /**
     * @param Closure(array{type: int, message: string, file: string, line: int}): void|null $onFatalError
     * @param Closure(int): void|null $onSignal
     */
    public static function register(
        ?LoggerInterface $logger = null,
        ?Closure $onFatalError = null,
        ?Closure $onSignal = null,
    ): void {
        if (self::$registered) {
            return;
        }

        self::$logger = $logger ?? new NullLogger();
        self::$onFatalError = $onFatalError;
        self::$onSignal = $onSignal;
        self::$registered = true;

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [self::class, 'handleSignal']);
            pcntl_signal(SIGINT, [self::class, 'handleSignal']);
            pcntl_signal(SIGHUP, [self::class, 'handleSignal']);
            pcntl_async_signals(true);
        }

        self::$logger->info('Error handler registered', [
            'error_handler' => 'yes',
            'exception_handler' => 'yes',
            'shutdown_handler' => 'yes',
            'signal_handler' => function_exists('pcntl_signal') ? 'yes' : 'no',
        ]);
    }

    public static function handleError(
        int $errno,
        string $errstr,
        string $errfile,
        int $errline,
    ): bool {
        if ((error_reporting() & $errno) === 0) {
            return false;
        }

        $errorType = self::getErrorType($errno);

        self::$logger?->error('PHP Error', [
            'type' => $errorType,
            'errno' => $errno,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);

        if (in_array($errno, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            fwrite(STDERR, sprintf(
                "[FATAL] %s: %s in %s on line %d\n",
                $errorType,
                $errstr,
                $errfile,
                $errline,
            ));
        }

        return false;
    }

    public static function handleException(Throwable $exception): void
    {
        self::$logger?->critical('Uncaught exception', [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);

        fwrite(STDERR, sprintf(
            "[CRITICAL] Uncaught %s: %s in %s:%d\n%s\n",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString(),
        ));
    }

    public static function handleShutdown(): void
    {
        if (self::$isShuttingDown) {
            return;
        }

        self::$isShuttingDown = true;

        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [
            E_ERROR,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_PARSE,
            E_RECOVERABLE_ERROR,
            E_USER_ERROR,
        ], true)) {
            $errorType = self::getErrorType($error['type']);

            self::$logger?->emergency('Fatal error detected on shutdown', [
                'type' => $errorType,
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);

            fwrite(STDERR, sprintf(
                "[FATAL] %s: %s in %s on line %d\n",
                $errorType,
                $error['message'],
                $error['file'],
                $error['line'],
            ));

            flush();

            if (self::$onFatalError !== null) {
                try {
                    (self::$onFatalError)($error);
                } catch (Throwable $e) {
                    self::$logger?->error('Error in fatal error callback', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        self::$logger?->info('Server shutdown normally', [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ]);
    }

    public static function handleSignal(int $signal): void
    {
        $signalName = self::getSignalName($signal);

        self::$logger?->warning('Received signal', [
            'signal' => $signal,
            'name' => $signalName,
            'memory_usage' => memory_get_usage(true),
        ]);

        fwrite(STDERR, sprintf("[SIGNAL] Received %s (%d)\n", $signalName, $signal));

        if (in_array($signal, [SIGTERM, SIGINT], true)) {
            self::$logger?->info('Graceful shutdown initiated');

            if (self::$onSignal !== null) {
                try {
                    (self::$onSignal)($signal);
                } catch (Throwable $e) {
                    self::$logger?->error('Error in signal callback', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private static function getErrorType(int $errno): string
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
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => "UNKNOWN ($errno)",
        };
    }

    private static function getSignalName(int $signal): string
    {
        if (!defined('SIGTERM')) {
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

    public static function reset(): void
    {
        self::$logger = null;
        self::$registered = false;
        self::$isShuttingDown = false;
        self::$onFatalError = null;
        self::$onSignal = null;

        restore_error_handler();
        restore_exception_handler();
    }
}
