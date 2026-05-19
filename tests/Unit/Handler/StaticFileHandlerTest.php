<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Handler;

use Duyler\HttpServer\Handler\StaticFileHandler;
use Duyler\HttpServer\Security\AuditLoggerInterface;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StaticFileHandlerTest extends TestCase
{
    private string $tempDir;
    private StaticFileHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/static_test_' . uniqid();
        mkdir($this->tempDir);

        $this->handler = new StaticFileHandler($this->tempDir, true, 1048576);
    }

    #[Override]
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

        $previousErrorReporting = error_reporting(0);
        unlink($file);
        error_reporting($previousErrorReporting);
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

    #[Test]
    public function is_static_file_returns_true_for_existing_file(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'content');

        $request = new ServerRequest('GET', '/test.txt');
        $this->assertTrue($this->handler->isStaticFile($request));
    }

    #[Test]
    public function is_static_file_returns_false_for_root(): void
    {
        $request = new ServerRequest('GET', '/');
        $this->assertFalse($this->handler->isStaticFile($request));
    }

    #[Test]
    public function is_static_file_returns_false_for_empty_path(): void
    {
        $request = new ServerRequest('GET', '');
        $this->assertFalse($this->handler->isStaticFile($request));
    }

    #[Test]
    public function is_static_file_returns_false_for_non_existent(): void
    {
        $request = new ServerRequest('GET', '/nonexistent.txt');
        $this->assertFalse($this->handler->isStaticFile($request));
    }

    #[Test]
    public function serves_file_without_cache_when_disabled(): void
    {
        $handler = new StaticFileHandler($this->tempDir, false, 1048576);
        $file = $this->tempDir . '/nocache.txt';
        file_put_contents($file, 'no cache content');

        $request = new ServerRequest('GET', '/nocache.txt');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('no cache content', (string) $response->getBody());

        $stats = $handler->getCacheStats();
        $this->assertSame(0, $stats['entries']);
    }

    #[Test]
    public function returns_304_for_if_modified_since(): void
    {
        $file = $this->tempDir . '/modified.txt';
        file_put_contents($file, 'modified content');

        $mtime = filemtime($file);
        $ifModifiedSince = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        $request = (new ServerRequest('GET', '/modified.txt'))
            ->withHeader('If-Modified-Since', $ifModifiedSince);

        $response = $this->handler->handle($request);
        $this->assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function invalidates_cache_on_file_change(): void
    {
        $file = $this->tempDir . '/changing.txt';
        file_put_contents($file, 'original');

        $request = new ServerRequest('GET', '/changing.txt');
        $response1 = $this->handler->handle($request);
        $this->assertSame('original', (string) $response1->getBody());

        clearstatcache(true, $file);
        file_put_contents($file, 'modified');
        touch($file, time() + 1);
        clearstatcache(true, $file);

        $response2 = $this->handler->handle($request);
        $this->assertSame('modified', (string) $response2->getBody());
    }

    #[Test]
    public function lru_single_entry_eviction(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1024, 1);

        $file1 = $this->tempDir . '/single1.txt';
        $file2 = $this->tempDir . '/single2.txt';
        file_put_contents($file1, 'a');
        file_put_contents($file2, 'b');

        $handler->handle(new ServerRequest('GET', '/single1.txt'));
        $stats = $handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);

        $handler->handle(new ServerRequest('GET', '/single2.txt'));
        $stats = $handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);
    }

    #[Test]
    public function lru_access_same_file_twice(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 3);

        $file = $this->tempDir . '/same.txt';
        file_put_contents($file, 'same content');

        $handler->handle(new ServerRequest('GET', '/same.txt'));
        $handler->handle(new ServerRequest('GET', '/same.txt'));
        $handler->handle(new ServerRequest('GET', '/same.txt'));

        $stats = $handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);
    }

    #[Test]
    public function mime_type_for_various_extensions(): void
    {
        $extensions = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
        ];

        foreach ($extensions as $ext => $expectedMime) {
            $file = $this->tempDir . "/test.{$ext}";
            file_put_contents($file, 'content');

            $request = new ServerRequest('GET', "/test.{$ext}");
            $response = $this->handler->handle($request);

            $this->assertSame($expectedMime, $response->getHeaderLine('Content-Type'));
        }
    }

    #[Test]
    public function unknown_extension_returns_octet_stream(): void
    {
        $file = $this->tempDir . '/test.unknownext';
        file_put_contents($file, 'content');

        $request = new ServerRequest('GET', '/test.unknownext');
        $response = $this->handler->handle($request);

        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function cache_size_exceeds_limit_returns_uncached(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 100, 100);

        $file = $this->tempDir . '/big.txt';
        file_put_contents($file, str_repeat('x', 200));

        $request = new ServerRequest('GET', '/big.txt');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(str_repeat('x', 200), (string) $response->getBody());

        $stats = $handler->getCacheStats();
        $this->assertSame(0, $stats['entries']);
    }

    #[Test]
    public function file_larger_than_max_cache_size_is_streamed(): void
    {
        $file = $this->tempDir . '/streamed.css';
        $content = str_repeat('body { margin: 0; } ', 100000);
        file_put_contents($file, $content);

        $request = new ServerRequest('GET', '/streamed.css');
        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/css', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function if_modified_since_future_returns_200(): void
    {
        $file = $this->tempDir . '/future.txt';
        file_put_contents($file, 'future content');

        $futureTime = time() + 3600;
        $ifModifiedSince = gmdate('D, d M Y H:i:s', $futureTime) . ' GMT';

        $request = (new ServerRequest('GET', '/future.txt'))
            ->withHeader('If-Modified-Since', $ifModifiedSince);

        $response = $this->handler->handle($request);
        $this->assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function invalidates_single_cached_file_on_change(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 1);

        $file = $this->tempDir . '/single.txt';
        file_put_contents($file, 'original');

        $handler->handle(new ServerRequest('GET', '/single.txt'));
        $stats = $handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);

        clearstatcache(true, $file);
        file_put_contents($file, 'modified');
        touch($file, time() + 1);
        clearstatcache(true, $file);

        $handler->handle(new ServerRequest('GET', '/single.txt'));
        $stats = $handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);
    }

    #[Test]
    public function eviction_removes_correct_size_from_cache(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 500, 3);

        $file1 = $this->tempDir . '/size1.txt';
        $file2 = $this->tempDir . '/size2.txt';
        file_put_contents($file1, str_repeat('a', 200));
        file_put_contents($file2, str_repeat('b', 400));

        $handler->handle(new ServerRequest('GET', '/size1.txt'));
        usleep(10000);
        $handler->handle(new ServerRequest('GET', '/size2.txt'));

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(500, $stats['size']);
    }

    #[Test]
    public function handle_nonexistent_public_path_returns_null(): void
    {
        $nonExistentDir = sys_get_temp_dir() . '/nonexistent_' . uniqid();
        $handler = new StaticFileHandler($nonExistentDir, true, 1048576);

        $request = new ServerRequest('GET', '/test.txt');
        $response = $handler->handle($request);

        $this->assertNull($response);
    }

    #[Test]
    public function handle_unreadable_file_returns_403_or_served_as_root(): void
    {
        $file = $this->tempDir . '/unreadable.txt';
        file_put_contents($file, 'unreadable content');
        chmod($file, 0000);

        $request = new ServerRequest('GET', '/unreadable.txt');
        $response = $this->handler->handle($request);

        $this->assertNotNull($response);

        if (0 === posix_getuid()) {
            $this->assertSame(200, $response->getStatusCode());
        } else {
            $this->assertSame(403, $response->getStatusCode());
        }

        chmod($file, 0644);
    }

    #[Test]
    public function lru_performance_with_many_files(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 10485760, 100);

        for ($i = 1; $i <= 100; $i++) {
            $file = $this->tempDir . "/file{$i}.txt";
            file_put_contents($file, "content{$i}");
        }

        $start = microtime(true);

        for ($round = 1; $round <= 10; $round++) {
            for ($i = 1; $i <= 100; $i++) {
                $handler->handle(new ServerRequest('GET', "/file{$i}.txt"));
            }
        }

        $elapsed = microtime(true) - $start;

        $stats = $handler->getCacheStats();
        $this->assertLessThanOrEqual(100, $stats['entries']);
        $this->assertLessThan(3.0, $elapsed, 'LRU operations should complete in under 3 seconds for 1000 operations');
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

    #[Test]
    public function logs_path_traversal_attempt(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'Hello World');

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->once())
            ->method('logPathTraversalAttempt')
            ->with(
                $this->callback(fn(string $ip): bool => true),
                '/../../../etc/passwd',
            );

        $handler = new StaticFileHandler(
            $this->tempDir,
            true,
            1048576,
            1000,
            $auditLogger,
        );

        $request = new ServerRequest('GET', '/../../../etc/passwd');

        $response = $handler->handle($request);

        $this->assertNull($response);
    }

    #[Test]
    public function does_not_log_path_traversal_for_non_existent_file(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects($this->never())
            ->method('logPathTraversalAttempt');

        $handler = new StaticFileHandler(
            $this->tempDir,
            true,
            1048576,
            1000,
            $auditLogger,
        );

        $request = new ServerRequest('GET', '/nonexistent.txt');

        $response = $handler->handle($request);

        $this->assertNull($response);
    }
}
