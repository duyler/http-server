<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Upload;

use Duyler\HttpServer\Upload\TempFileManager;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TempFileManagerTest extends TestCase
{
    private TempFileManager $manager;

    #[Override]
    protected function setUp(): void
    {
        $this->manager = new TempFileManager();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->cleanup();
        }
    }

    #[Test]
    public function creates_temporary_file_with_default_prefix(): void
    {
        $tmpFile = $this->manager->create();

        $this->assertFileExists($tmpFile);
        $this->assertStringContainsString('upload_', basename($tmpFile));
        $this->assertSame(1, $this->manager->getTrackedFilesCount());
    }

    #[Test]
    public function creates_temporary_file_with_custom_prefix(): void
    {
        $tmpFile = $this->manager->create('test_');

        $this->assertFileExists($tmpFile);
        $this->assertStringContainsString('test_', basename($tmpFile));
    }

    #[Test]
    public function tracks_multiple_temporary_files(): void
    {
        $tmpFile1 = $this->manager->create();
        $tmpFile2 = $this->manager->create();
        $tmpFile3 = $this->manager->create();

        $this->assertFileExists($tmpFile1);
        $this->assertFileExists($tmpFile2);
        $this->assertFileExists($tmpFile3);
        $this->assertSame(3, $this->manager->getTrackedFilesCount());
    }

    #[Test]
    public function cleanup_removes_all_temporary_files(): void
    {
        $tmpFile1 = $this->manager->create();
        $tmpFile2 = $this->manager->create();

        $this->assertFileExists($tmpFile1);
        $this->assertFileExists($tmpFile2);

        $this->manager->cleanup();

        $this->assertFileDoesNotExist($tmpFile1);
        $this->assertFileDoesNotExist($tmpFile2);
        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }

    #[Test]
    public function cleanup_handles_already_deleted_files(): void
    {
        $tmpFile = $this->manager->create();
        unlink($tmpFile);

        $this->manager->cleanup();

        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }

    #[Test]
    public function destructor_cleans_up_files(): void
    {
        $tmpFile = $this->manager->create();
        $this->assertFileExists($tmpFile);

        unset($this->manager);

        $this->assertFileDoesNotExist($tmpFile);
    }

    #[Test]
    public function created_files_can_be_written_to(): void
    {
        $tmpFile = $this->manager->create();
        $content = 'test content';

        file_put_contents($tmpFile, $content);

        $this->assertSame($content, file_get_contents($tmpFile));
    }

    #[Test]
    public function cleanup_can_be_called_multiple_times(): void
    {
        $tmpFile = $this->manager->create();

        $this->manager->cleanup();
        $this->manager->cleanup();

        $this->assertFileDoesNotExist($tmpFile);
        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }

    #[Test]
    public function files_created_after_cleanup_are_tracked_separately(): void
    {
        $tmpFile1 = $this->manager->create();
        $this->manager->cleanup();

        $this->assertFileDoesNotExist($tmpFile1);

        $tmpFile2 = $this->manager->create();

        $this->assertFileExists($tmpFile2);
        $this->assertSame(1, $this->manager->getTrackedFilesCount());
    }
}
