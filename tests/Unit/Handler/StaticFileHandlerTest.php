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

    #[Test]
    public function caches_small_files(): void
    {
        $file = $this->tempDir . '/small.txt';
        $content = str_repeat('a', 1024);
        file_put_contents($file, $content);

        $request = new ServerRequest('GET', '/small.txt');
        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($content, (string) $response->getBody());

        $stats = $this->handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);
    }

    #[Test]
    public function streams_large_files_without_caching(): void
    {
        $file = $this->tempDir . '/large.bin';
        $size = 2 * 1024 * 1024;
        $content = str_repeat('x', $size);
        file_put_contents($file, $content);

        $request = new ServerRequest('GET', '/large.bin');
        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((string) $size, $response->getHeaderLine('Content-Length'));

        $stats = $this->handler->getCacheStats();
        $this->assertSame(0, $stats['entries'], 'Large files should not be cached');
    }

    #[Test]
    public function streams_file_at_cache_boundary(): void
    {
        $file = $this->tempDir . '/boundary.bin';
        $size = 1048577;
        file_put_contents($file, str_repeat('b', $size));

        $request = new ServerRequest('GET', '/boundary.bin');
        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame((string) $size, $response->getHeaderLine('Content-Length'));

        $stats = $this->handler->getCacheStats();
        $this->assertSame(0, $stats['entries'], 'Files larger than cache should be streamed');
    }

    #[Test]
    public function does_not_cache_when_cache_full(): void
    {
        $file1 = $this->tempDir . '/file1.bin';
        $file2 = $this->tempDir . '/file2.bin';

        file_put_contents($file1, str_repeat('x', 600000));
        file_put_contents($file2, str_repeat('y', 600000));

        $request1 = new ServerRequest('GET', '/file1.bin');
        $request2 = new ServerRequest('GET', '/file2.bin');

        $this->handler->handle($request1);
        $response2 = $this->handler->handle($request2);

        $this->assertSame(200, $response2->getStatusCode());

        $stats = $this->handler->getCacheStats();
        $this->assertLessThanOrEqual($this->handler->getCacheStats()['max_size'], $stats['size']);
    }

    #[Test]
    public function streams_file_preserves_mime_type(): void
    {
        $file = $this->tempDir . '/large.pdf';
        $size = 2 * 1024 * 1024;
        file_put_contents($file, str_repeat('p', $size));

        $request = new ServerRequest('GET', '/large.pdf');
        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function lru_evicts_least_recently_used_file(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 3);

        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        $file3 = $this->tempDir . '/file3.txt';
        $file4 = $this->tempDir . '/file4.txt';

        file_put_contents($file1, 'content1');
        file_put_contents($file2, 'content2');
        file_put_contents($file3, 'content3');
        file_put_contents($file4, 'content4');

        $handler->handle(new ServerRequest('GET', '/file1.txt'));
        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/file2.txt'));
        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/file3.txt'));

        $stats = $handler->getCacheStats();
        $this->assertSame(3, $stats['entries']);

        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/file4.txt'));

        $stats = $handler->getCacheStats();
        $this->assertSame(3, $stats['entries'], 'Cache should maintain max 3 files');

        $response = $handler->handle(new ServerRequest('GET', '/file1.txt'));
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function lru_updates_access_time_on_cache_hit(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 2);

        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        $file3 = $this->tempDir . '/file3.txt';

        file_put_contents($file1, 'content1');
        file_put_contents($file2, 'content2');
        file_put_contents($file3, 'content3');

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

        $this->assertSame('content1', (string) $response1->getBody());
        $this->assertSame('content3', (string) $response3->getBody());
    }

    #[Test]
    public function lru_respects_max_files_limit(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 10485760, 5);

        for ($i = 1; $i <= 10; $i++) {
            $file = $this->tempDir . "/file{$i}.txt";
            file_put_contents($file, "content{$i}");
            $handler->handle(new ServerRequest('GET', "/file{$i}.txt"));
            usleep(5000);
        }

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(5, $stats['entries']);
    }

    #[Test]
    public function lru_evicts_when_size_limit_reached(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 2048, 100);

        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        $file3 = $this->tempDir . '/file3.txt';

        file_put_contents($file1, str_repeat('a', 800));
        file_put_contents($file2, str_repeat('b', 800));
        file_put_contents($file3, str_repeat('c', 800));

        $handler->handle(new ServerRequest('GET', '/file1.txt'));
        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/file2.txt'));
        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/file3.txt'));

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(2048, $stats['size']);
        $this->assertLessThanOrEqual(3, $stats['entries']);
    }

    #[Test]
    public function lru_cache_stats_include_max_files(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 50);

        $stats = $handler->getCacheStats();

        $this->assertArrayHasKey('max_files', $stats);
        $this->assertSame(50, $stats['max_files']);
    }

    #[Test]
    public function lru_eviction_preserves_most_recent_files(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 3);

        for ($i = 1; $i <= 5; $i++) {
            $file = $this->tempDir . "/file{$i}.txt";
            file_put_contents($file, "content{$i}");
            $handler->handle(new ServerRequest('GET', "/file{$i}.txt"));
            usleep(10000);
        }

        $stats = $handler->getCacheStats();
        $this->assertSame(3, $stats['entries']);

        $response3 = $handler->handle(new ServerRequest('GET', '/file3.txt'));
        $response4 = $handler->handle(new ServerRequest('GET', '/file4.txt'));
        $response5 = $handler->handle(new ServerRequest('GET', '/file5.txt'));

        $this->assertSame('content3', (string) $response3->getBody());
        $this->assertSame('content4', (string) $response4->getBody());
        $this->assertSame('content5', (string) $response5->getBody());
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
