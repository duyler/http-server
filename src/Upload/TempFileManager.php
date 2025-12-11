<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Upload;

use RuntimeException;

final class TempFileManager
{
    /** @var array<string> */
    private array $files = [];

    public function create(string $prefix = 'upload_'): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($tmpFile === false) {
            throw new RuntimeException('Failed to create temporary file');
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
