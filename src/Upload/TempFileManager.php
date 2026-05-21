<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Upload;

use RuntimeException;
use WeakReference;

final class TempFileManager
{
    /** @var array<string> */
    private array $files = [];

    private bool $shutdownRegistered = false;

    public function create(string $prefix = 'upload_'): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), $prefix);

        if (false === $tmpFile) {
            throw new RuntimeException('Failed to create temporary file');
        }

        if (false === $this->shutdownRegistered) {
            $weakRef = WeakReference::create($this);
            register_shutdown_function(static function () use ($weakRef): void {
                $manager = $weakRef->get();
                if (null !== $manager) {
                    $manager->cleanup();
                }
            });
            $this->shutdownRegistered = true;
        }

        $this->files[] = $tmpFile;

        return $tmpFile;
    }

    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->files = [];
    }

    public function getTrackedFilesCount(): int
    {
        return count($this->files);
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
