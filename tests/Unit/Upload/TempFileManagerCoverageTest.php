<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Upload;

use Duyler\HttpServer\Upload\TempFileManager;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TempFileManagerCoverageTest extends TestCase
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
        $this->manager->cleanup();
    }

    #[Test]
    public function created_file_is_in_system_temp_directory(): void
    {
        $tmpFile = $this->manager->create();

        $this->assertStringStartsWith(sys_get_temp_dir(), $tmpFile);
    }

    #[Test]
    public function creates_file_with_empty_prefix(): void
    {
        $tmpFile = $this->manager->create('');

        $this->assertFileExists($tmpFile);
    }

    #[Test]
    public function binary_content_is_preserved(): void
    {
        $tmpFile = $this->manager->create();
        $binaryContent = pack('C*', ...range(0, 255));

        file_put_contents($tmpFile, $binaryContent);

        $this->assertSame($binaryContent, file_get_contents($tmpFile));
    }

    #[Test]
    public function large_content_is_preserved(): void
    {
        $tmpFile = $this->manager->create();
        $largeContent = str_repeat('ABCDEFGHIJKLMNOP', 65536);

        file_put_contents($tmpFile, $largeContent);

        $this->assertSame($largeContent, file_get_contents($tmpFile));
    }

    #[Test]
    public function unique_paths_for_multiple_creates(): void
    {
        $file1 = $this->manager->create();
        $file2 = $this->manager->create();
        $file3 = $this->manager->create();

        $this->assertNotSame($file1, $file2);
        $this->assertNotSame($file2, $file3);
        $this->assertNotSame($file1, $file3);
    }

    #[Test]
    public function tracked_count_increments_per_create(): void
    {
        $this->assertSame(0, $this->manager->getTrackedFilesCount());

        $this->manager->create();
        $this->assertSame(1, $this->manager->getTrackedFilesCount());

        $this->manager->create();
        $this->assertSame(2, $this->manager->getTrackedFilesCount());

        $this->manager->create();
        $this->assertSame(3, $this->manager->getTrackedFilesCount());
    }

    #[Test]
    public function cleanup_does_not_affect_separate_manager(): void
    {
        $otherManager = new TempFileManager();

        $otherFile = $otherManager->create();
        $thisFile = $this->manager->create();

        $this->manager->cleanup();

        $this->assertFileExists($otherFile);
        $this->assertFileDoesNotExist($thisFile);

        $otherManager->cleanup();
    }

    #[Test]
    public function file_can_be_appended_after_creation(): void
    {
        $tmpFile = $this->manager->create();

        file_put_contents($tmpFile, 'first');
        file_put_contents($tmpFile, ' second', FILE_APPEND);

        $this->assertSame('first second', file_get_contents($tmpFile));
    }

    #[Test]
    public function creates_file_with_numeric_prefix(): void
    {
        $tmpFile = $this->manager->create('123_');

        $this->assertFileExists($tmpFile);
        $this->assertStringContainsString('123_', basename($tmpFile));
    }

    #[Test]
    public function creates_file_with_underscore_prefix(): void
    {
        $tmpFile = $this->manager->create('_');

        $this->assertFileExists($tmpFile);
    }

    #[Test]
    public function created_file_is_writable(): void
    {
        $tmpFile = $this->manager->create();

        $result = file_put_contents($tmpFile, 'write test');

        $this->assertNotFalse($result);
        $this->assertSame('write test', file_get_contents($tmpFile));
    }

    #[Test]
    public function created_file_is_readable(): void
    {
        $tmpFile = $this->manager->create();

        $this->assertTrue(is_readable($tmpFile));
    }

    #[Test]
    public function multiple_create_cleanup_cycles(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $files = [];
            for ($j = 0; $j < 5; $j++) {
                $files[] = $this->manager->create();
            }

            $this->assertSame(5, $this->manager->getTrackedFilesCount());

            foreach ($files as $file) {
                $this->assertFileExists($file);
            }

            $this->manager->cleanup();

            $this->assertSame(0, $this->manager->getTrackedFilesCount());

            foreach ($files as $file) {
                $this->assertFileDoesNotExist($file);
            }
        }
    }

    #[Test]
    public function handles_many_files(): void
    {
        $files = [];
        for ($i = 0; $i < 50; $i++) {
            $files[] = $this->manager->create();
        }

        $this->assertSame(50, $this->manager->getTrackedFilesCount());

        $this->manager->cleanup();

        $this->assertSame(0, $this->manager->getTrackedFilesCount());

        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    #[Test]
    public function unicode_content_is_preserved(): void
    {
        $tmpFile = $this->manager->create();
        $unicodeContent = 'Привет мир 🌍 日本語テスト';

        file_put_contents($tmpFile, $unicodeContent);

        $this->assertSame($unicodeContent, file_get_contents($tmpFile));
    }

    #[Test]
    public function empty_file_has_zero_size(): void
    {
        $tmpFile = $this->manager->create();

        $this->assertSame(0, filesize($tmpFile));
    }

    #[Test]
    public function cleanup_after_manual_file_deletion_resets_count(): void
    {
        $file1 = $this->manager->create();
        $file2 = $this->manager->create();

        unlink($file1);

        $this->manager->cleanup();

        $this->assertSame(0, $this->manager->getTrackedFilesCount());
        $this->assertFileDoesNotExist($file2);
    }

    #[Test]
    public function file_with_long_prefix(): void
    {
        $longPrefix = str_repeat('a', 60);
        $tmpFile = $this->manager->create($longPrefix);

        $this->assertFileExists($tmpFile);
    }
}
