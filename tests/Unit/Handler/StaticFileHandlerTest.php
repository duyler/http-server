<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Handler;

use Duyler\HttpServer\Handler\StaticFileHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StaticFileHandlerTest extends TestCase
{
    private string $tempDir;
    private StaticFileHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/static_test_' . uniqid();
        mkdir($this->tempDir);

        $this->handler = new StaticFileHandler($this->tempDir, true, 1048576);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function returns_null_for_non_existent_file(): void
    {
        $request = new ServerRequest('GET', '/nonexistent.txt');

        $response = $this->handler->handle($request);

        $this->assertNull($response);
    }

    #[Test]
    public function serves_existing_file(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'Hello World');

        $request = new ServerRequest('GET', '/test.txt');
        $response = $this->handler->handle($request);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hello World', (string) $response->getBody());
    }

    #[Test]
    public function sets_correct_content_type(): void
    {
        $file = $this->tempDir . '/test.html';
        file_put_contents($file, '<html></html>');

        $request = new ServerRequest('GET', '/test.html');
        $response = $this->handler->handle($request);

        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function sets_cache_headers(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'test');

        $request = new ServerRequest('GET', '/test.txt');
        $response = $this->handler->handle($request);

        $this->assertTrue($response->hasHeader('Last-Modified'));
        $this->assertTrue($response->hasHeader('ETag'));
        $this->assertTrue($response->hasHeader('Cache-Control'));
    }

    #[Test]
    public function returns_304_for_matching_etag(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'test');

        $mtime = filemtime($file);
        $size = filesize($file);
        $etag = sprintf('"%x-%x"', $mtime, $size);

        $request = (new ServerRequest('GET', '/test.txt'))
            ->withHeader('If-None-Match', $etag);

        $response = $this->handler->handle($request);

        $this->assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function caches_file_content(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'cached content');

        $request = new ServerRequest('GET', '/test.txt');

        $this->handler->handle($request);
        $this->handler->handle($request);

        $stats = $this->handler->getCacheStats();

        $this->assertSame(1, $stats['entries']);
        $this->assertGreaterThan(0, $stats['size']);
    }

    #[Test]
    public function clears_cache(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'test');

        $request = new ServerRequest('GET', '/test.txt');
        $this->handler->handle($request);

        $this->handler->clearCache();
        $stats = $this->handler->getCacheStats();

        $this->assertSame(0, $stats['entries']);
        $this->assertSame(0, $stats['size']);
    }

    #[Test]
    public function prevents_directory_traversal(): void
    {
        $file = $this->tempDir . '/../outside.txt';
        file_put_contents($file, 'outside');

        $request = new ServerRequest('GET', '/../outside.txt');
        $response = $this->handler->handle($request);

        $this->assertNull($response);

        @unlink($file);
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
