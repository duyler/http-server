<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Handler\StaticFileHandler;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LargeFileMemoryTest extends TestCase
{
    private string $tempDir;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/large_test_' . uniqid();
        mkdir($this->tempDir);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function large_file_streaming_does_not_cause_memory_leak(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576);

        $largeFile = $this->tempDir . '/large.bin';
        $fileSize = 10 * 1024 * 1024;

        $handle = fopen($largeFile, 'w');
        $chunkSize = 1024 * 1024;
        for ($i = 0; $i < 10; $i++) {
            fwrite($handle, str_repeat('x', $chunkSize));
        }
        fclose($handle);

        $this->assertFileExists($largeFile);
        $this->assertSame($fileSize, filesize($largeFile));

        $memoryBefore = memory_get_usage(true);

        $request = new ServerRequest('GET', '/large.bin');
        $response = $handler->handle($request);

        $memoryAfter = memory_get_usage(true);
        $memoryDiff = $memoryAfter - $memoryBefore;

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((string) $fileSize, $response->getHeaderLine('Content-Length'));

        $this->assertLessThan(
            2 * 1024 * 1024,
            $memoryDiff,
            sprintf(
                'Memory usage should not increase significantly. Actual: %d bytes (%.2f MB)',
                $memoryDiff,
                $memoryDiff / 1024 / 1024,
            ),
        );

        $stats = $handler->getCacheStats();
        $this->assertSame(0, $stats['entries'], 'Large files should not be cached');
    }

    #[Test]
    public function multiple_large_files_dont_accumulate_memory(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576);

        for ($i = 1; $i <= 3; $i++) {
            $file = $this->tempDir . "/large{$i}.bin";
            file_put_contents($file, str_repeat('x', 5 * 1024 * 1024));
        }

        $memoryBefore = memory_get_usage(true);

        for ($i = 1; $i <= 3; $i++) {
            $request = new ServerRequest('GET', "/large{$i}.bin");
            $response = $handler->handle($request);
            $this->assertSame(200, $response->getStatusCode());
        }

        $memoryAfter = memory_get_usage(true);
        $memoryDiff = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            3 * 1024 * 1024,
            $memoryDiff,
            sprintf('Memory should not accumulate. Actual: %.2f MB', $memoryDiff / 1024 / 1024),
        );
    }

    #[Test]
    public function small_files_are_cached_large_files_are_not(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576);

        $smallFile = $this->tempDir . '/small.txt';
        file_put_contents($smallFile, str_repeat('a', 1024));

        $largeFile = $this->tempDir . '/large.bin';
        file_put_contents($largeFile, str_repeat('x', 2 * 1024 * 1024));

        $smallRequest = new ServerRequest('GET', '/small.txt');
        $largeRequest = new ServerRequest('GET', '/large.bin');

        $smallResponse = $handler->handle($smallRequest);
        $largeResponse = $handler->handle($largeRequest);

        $this->assertSame(200, $smallResponse->getStatusCode());
        $this->assertSame(200, $largeResponse->getStatusCode());

        $stats = $handler->getCacheStats();

        $this->assertSame(1, $stats['entries'], 'Only small file should be cached');
        $this->assertLessThan(2048, $stats['size'], 'Cache size should only include small file');
    }

    #[Test]
    public function cache_boundary_exactly_at_limit(): void
    {
        $maxCacheSize = 1048576;
        $handler = new StaticFileHandler($this->tempDir, true, $maxCacheSize);

        $exactFile = $this->tempDir . '/exact.bin';
        file_put_contents($exactFile, str_repeat('x', $maxCacheSize));

        $overFile = $this->tempDir . '/over.bin';
        file_put_contents($overFile, str_repeat('y', $maxCacheSize + 1));

        $exactRequest = new ServerRequest('GET', '/exact.bin');
        $overRequest = new ServerRequest('GET', '/over.bin');

        $exactResponse = $handler->handle($exactRequest);
        $this->assertSame(200, $exactResponse->getStatusCode());

        $handler->clearCache();

        $overResponse = $handler->handle($overRequest);
        $this->assertSame(200, $overResponse->getStatusCode());

        $stats = $handler->getCacheStats();

        $this->assertSame(0, $stats['entries'], 'File over max should not be cached (streamed)');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
