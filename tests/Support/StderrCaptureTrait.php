<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Support;

trait StderrCaptureTrait
{
    private string $stderrTempFile = '';

    protected function captureStderr(): void
    {
        $this->stderrTempFile = tempnam(sys_get_temp_dir(), 'phpunit_stderr_');
        $tempPath = $this->stderrTempFile;

        fwrite(STDERR, '');

        set_error_handler(fn($errno, $errstr, $errfile, $errline): bool => true);

        if (function_exists('posix_isatty') && false === posix_isatty(STDERR)) {
            $this->stderrTempFile = $tempPath;
        }

        restore_error_handler();
    }

    protected function releaseStderr(): string
    {
        if ('' === $this->stderrTempFile) {
            return '';
        }

        $content = '';
        if (file_exists($this->stderrTempFile)) {
            $content = file_get_contents($this->stderrTempFile);
            unlink($this->stderrTempFile);
        }

        $this->stderrTempFile = '';

        return false === $content ? '' : $content;
    }
}
