<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Handler\StaticFileHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LRUCacheIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/lru_test_' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function handler_caches_files_with_lru_eviction(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 10240, 3);

        for ($i = 1; $i <= 5; $i++) {
            $file = $this->tempDir . "/file{$i}.txt";
            file_put_contents($file, "Content {$i}");
        }

        for ($i = 1; $i <= 5; $i++) {
            $response = $handler->handle(new ServerRequest('GET', "/file{$i}.txt"));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame("Content {$i}", (string) $response->getBody());
            usleep(10000);
        }

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(3, $stats['entries']);
    }

    #[Test]
    public function handler_serves_large_files_without_caching(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1024, 10);

        $largeFile = $this->tempDir . '/large.bin';
        file_put_contents($largeFile, str_repeat('X', 2048));

        $response = $handler->handle(new ServerRequest('GET', '/large.bin'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2048', $response->getHeaderLine('Content-Length'));

        $stats = $handler->getCacheStats();
        $this->assertSame(0, $stats['entries'], 'Large files should not be cached');
    }

    #[Test]
    public function handler_updates_lru_on_access(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 10240, 2);

        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        $file3 = $this->tempDir . '/file3.txt';

        file_put_contents($file1, 'Content 1');
        file_put_contents($file2, 'Content 2');
        file_put_contents($file3, 'Content 3');

        $handler->handle(new ServerRequest('GET', '/file1.txt'));
        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/file2.txt'));
        usleep(10000);

        $handler->handle(new ServerRequest('GET', '/file1.txt'));
        usleep(10000);

        $handler->handle(new ServerRequest('GET', '/file3.txt'));

        $stats = $handler->getCacheStats();
        $this->assertSame(2, $stats['entries']);

        $response1 = $handler->handle(new ServerRequest('GET', '/file1.txt'));
        $response3 = $handler->handle(new ServerRequest('GET', '/file3.txt'));

        $this->assertSame('Content 1', (string) $response1->getBody());
        $this->assertSame('Content 3', (string) $response3->getBody());
    }

    #[Test]
    public function handler_evicts_by_size_limit(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 2000, 100);

        for ($i = 1; $i <= 5; $i++) {
            $file = $this->tempDir . "/file{$i}.txt";
            file_put_contents($file, str_repeat("X{$i}", 400));
            $handler->handle(new ServerRequest('GET', "/file{$i}.txt"));
            usleep(10000);
        }

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(2000, $stats['size']);
    }

    #[Test]
    public function handler_maintains_cache_consistency(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 5120, 5);

        for ($i = 1; $i <= 10; $i++) {
            $file = $this->tempDir . "/test{$i}.txt";
            file_put_contents($file, "Test {$i}");
            $response = $handler->handle(new ServerRequest('GET', "/test{$i}.txt"));
            $this->assertSame(200, $response->getStatusCode());
            usleep(5000);
        }

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(5, $stats['entries']);
        $this->assertLessThanOrEqual(5120, $stats['size']);

        for ($i = 6; $i <= 10; $i++) {
            $response = $handler->handle(new ServerRequest('GET', "/test{$i}.txt"));
            $this->assertSame("Test {$i}", (string) $response->getBody());
        }
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

