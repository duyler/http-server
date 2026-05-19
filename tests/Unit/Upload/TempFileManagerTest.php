<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Upload;

use Duyler\HttpServer\Upload\TempFileManager;
use Override;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

    public function testCreatesTemporaryFileWithDefaultPrefix(): void
    {
        $tmpFile = $this->manager->create();

        $this->assertFileExists($tmpFile);
        $this->assertStringContainsString('upload_', basename($tmpFile));
        $this->assertSame(1, $this->manager->getTrackedFilesCount());
    }

    public function testCreatesTemporaryFileWithCustomPrefix(): void
    {
        $tmpFile = $this->manager->create('test_');

        $this->assertFileExists($tmpFile);
        $this->assertStringContainsString('test_', basename($tmpFile));
    }

    public function testTracksMultipleTemporaryFiles(): void
    {
        $tmpFile1 = $this->manager->create();
        $tmpFile2 = $this->manager->create();
        $tmpFile3 = $this->manager->create();

        $this->assertFileExists($tmpFile1);
        $this->assertFileExists($tmpFile2);
        $this->assertFileExists($tmpFile3);
        $this->assertSame(3, $this->manager->getTrackedFilesCount());
    }

    public function testCleanupRemovesAllTemporaryFiles(): void
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

    public function testCleanupHandlesAlreadyDeletedFiles(): void
    {
        $tmpFile = $this->manager->create();
        unlink($tmpFile);

        $this->manager->cleanup();

        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }

    public function testDestructorCleansUpFiles(): void
    {
        $tmpFile = $this->manager->create();
        $this->assertFileExists($tmpFile);

        unset($this->manager);

        $this->assertFileDoesNotExist($tmpFile);
    }

    public function testCreatedFilesCanBeWrittenTo(): void
    {
        $tmpFile = $this->manager->create();
        $content = 'test content';

        file_put_contents($tmpFile, $content);

        $this->assertSame($content, file_get_contents($tmpFile));
    }

    public function testCleanupCanBeCalledMultipleTimes(): void
    {
        $tmpFile = $this->manager->create();

        $this->manager->cleanup();
        $this->manager->cleanup();

        $this->assertFileDoesNotExist($tmpFile);
        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }

    public function testFilesCreatedAfterCleanupAreTrackedSeparately(): void
    {
        $tmpFile1 = $this->manager->create();
        $this->manager->cleanup();

        $this->assertFileDoesNotExist($tmpFile1);

        $tmpFile2 = $this->manager->create();

        $this->assertFileExists($tmpFile2);
        $this->assertSame(1, $this->manager->getTrackedFilesCount());
    }

    public function testShutdownRegisteredFlagIsFalseBeforeCreate(): void
    {
        $reflection = new ReflectionProperty($this->manager, 'shutdownRegistered');
        $this->assertFalse($reflection->getValue($this->manager));
    }

    public function testShutdownRegisteredFlagIsSetAfterFirstCreate(): void
    {
        $this->manager->create();

        $reflection = new ReflectionProperty($this->manager, 'shutdownRegistered');
        $this->assertTrue($reflection->getValue($this->manager));
    }

    public function testShutdownRegisteredFlagRemainsTrueAfterMultipleCreates(): void
    {
        $this->manager->create();
        $this->manager->create();
        $this->manager->create();

        $reflection = new ReflectionProperty($this->manager, 'shutdownRegistered');
        $this->assertTrue($reflection->getValue($this->manager));
    }

    public function testCleanupCalledAfterShutdownFunctionRegistration(): void
    {
        $tmpFile = $this->manager->create();
        $this->assertFileExists($tmpFile);

        $this->manager->cleanup();

        $this->assertFileDoesNotExist($tmpFile);
        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }

    public function testCleanupIsIdempotentAfterShutdownRegistration(): void
    {
        $this->manager->create();
        $this->manager->cleanup();
        $this->manager->cleanup();

        $this->assertSame(0, $this->manager->getTrackedFilesCount());
    }
}
