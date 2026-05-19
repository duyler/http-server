<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Handler;

use Duyler\HttpServer\Handler\StaticFileHandler;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StaticFileHandlerCoverageTest extends TestCase
{
    private string $tempDir;
    private StaticFileHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/static_coverage_' . uniqid();
        mkdir($this->tempDir);
        mkdir($this->tempDir . '/sub');

        $this->handler = new StaticFileHandler($this->tempDir, true, 1048576);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    #[Test]
    public function cache_initial_stats_are_zero(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576);

        $stats = $handler->getCacheStats();

        $this->assertSame(0, $stats['entries']);
        $this->assertSame(0, $stats['size']);
        $this->assertSame(1048576, $stats['max_size']);
    }

    #[Test]
    public function clear_cache_resets_all_stats(): void
    {
        $file = $this->tempDir . '/test.txt';
        file_put_contents($file, 'content');

        $this->handler->handle(new ServerRequest('GET', '/test.txt'));
        $this->handler->clearCache();

        $stats = $this->handler->getCacheStats();

        $this->assertSame(0, $stats['entries']);
        $this->assertSame(0, $stats['size']);
    }

    #[Test]
    public function mime_type_htm(): void
    {
        $file = $this->tempDir . '/page.htm';
        file_put_contents($file, '<html>');

        $response = $this->handler->handle(new ServerRequest('GET', '/page.htm'));

        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_xml(): void
    {
        $file = $this->tempDir . '/data.xml';
        file_put_contents($file, '<root/>');

        $response = $this->handler->handle(new ServerRequest('GET', '/data.xml'));

        $this->assertSame('application/xml', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_jpg(): void
    {
        $file = $this->tempDir . '/photo.jpg';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/photo.jpg'));

        $this->assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_jpeg(): void
    {
        $file = $this->tempDir . '/photo.jpeg';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/photo.jpeg'));

        $this->assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_gif(): void
    {
        $file = $this->tempDir . '/anim.gif';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/anim.gif'));

        $this->assertSame('image/gif', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_ico(): void
    {
        $file = $this->tempDir . '/favicon.ico';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/favicon.ico'));

        $this->assertSame('image/x-icon', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_zip(): void
    {
        $file = $this->tempDir . '/archive.zip';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/archive.zip'));

        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_woff(): void
    {
        $file = $this->tempDir . '/font.woff';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/font.woff'));

        $this->assertSame('font/woff', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_woff2(): void
    {
        $file = $this->tempDir . '/font.woff2';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/font.woff2'));

        $this->assertSame('font/woff2', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_ttf(): void
    {
        $file = $this->tempDir . '/font.ttf';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/font.ttf'));

        $this->assertSame('font/ttf', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_otf(): void
    {
        $file = $this->tempDir . '/font.otf';
        file_put_contents($file, 'binary');

        $response = $this->handler->handle(new ServerRequest('GET', '/font.otf'));

        $this->assertSame('font/otf', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function mime_type_txt(): void
    {
        $file = $this->tempDir . '/readme.txt';
        file_put_contents($file, 'text content');

        $response = $this->handler->handle(new ServerRequest('GET', '/readme.txt'));

        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function serves_file_from_subdirectory(): void
    {
        $file = $this->tempDir . '/sub/nested.txt';
        file_put_contents($file, 'nested content');

        $response = $this->handler->handle(new ServerRequest('GET', '/sub/nested.txt'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nested content', (string) $response->getBody());
    }

    #[Test]
    public function is_static_file_for_subdirectory_file(): void
    {
        $file = $this->tempDir . '/sub/deep.txt';
        file_put_contents($file, 'deep');

        $this->assertTrue($this->handler->isStaticFile(new ServerRequest('GET', '/sub/deep.txt')));
    }

    #[Test]
    public function returns_null_for_directory_path(): void
    {
        mkdir($this->tempDir . '/subdir');

        $response = $this->handler->handle(new ServerRequest('GET', '/subdir'));

        $this->assertNull($response);
    }

    #[Test]
    public function is_static_file_returns_false_for_directory(): void
    {
        mkdir($this->tempDir . '/adir');

        $this->assertFalse($this->handler->isStaticFile(new ServerRequest('GET', '/adir')));
    }

    #[Test]
    public function if_modified_since_empty_string_returns_200(): void
    {
        $file = $this->tempDir . '/empty_header.txt';
        file_put_contents($file, 'content');

        $request = (new ServerRequest('GET', '/empty_header.txt'))
            ->withHeader('If-Modified-Since', '');

        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function if_modified_since_invalid_date_returns_200(): void
    {
        $file = $this->tempDir . '/invalid_date.txt';
        file_put_contents($file, 'content');

        $request = (new ServerRequest('GET', '/invalid_date.txt'))
            ->withHeader('If-Modified-Since', 'not-a-date');

        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function if_modified_since_old_date_returns_200(): void
    {
        $file = $this->tempDir . '/old_date.txt';
        file_put_contents($file, 'content');

        $oldDate = gmdate('D, d M Y H:i:s', 0) . ' GMT';

        $request = (new ServerRequest('GET', '/old_date.txt'))
            ->withHeader('If-Modified-Since', $oldDate);

        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function range_header_is_ignored_returns_full_content(): void
    {
        $file = $this->tempDir . '/range.txt';
        file_put_contents($file, 'full content');

        $request = (new ServerRequest('GET', '/range.txt'))
            ->withHeader('Range', 'bytes=0-3');

        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('full content', (string) $response->getBody());
    }

    #[Test]
    public function is_static_file_returns_false_for_nonexistent_public_path(): void
    {
        $nonExistent = sys_get_temp_dir() . '/nonexistent_' . uniqid();
        $handler = new StaticFileHandler($nonExistent, true, 1048576);

        $result = $handler->isStaticFile(new ServerRequest('GET', '/test.txt'));

        $this->assertFalse($result);
    }

    #[Test]
    public function cache_disabled_reads_file_every_time(): void
    {
        $handler = new StaticFileHandler($this->tempDir, false, 1048576);
        $file = $this->tempDir . '/nocache.txt';
        file_put_contents($file, 'original');

        $handler->handle(new ServerRequest('GET', '/nocache.txt'));

        clearstatcache(true, $file);
        file_put_contents($file, 'updated');
        touch($file, time() + 1);
        clearstatcache(true, $file);

        $response = $handler->handle(new ServerRequest('GET', '/nocache.txt'));

        $this->assertSame('updated', (string) $response->getBody());

        $stats = $handler->getCacheStats();
        $this->assertSame(0, $stats['entries']);
    }

    #[Test]
    public function etag_format_contains_mtime_and_size(): void
    {
        $file = $this->tempDir . '/etag.txt';
        file_put_contents($file, 'etag content');

        $response = $this->handler->handle(new ServerRequest('GET', '/etag.txt'));

        $etag = $response->getHeaderLine('ETag');

        $mtime = filemtime($file);
        $size = filesize($file);
        $expectedEtag = sprintf('"%x-%x"', $mtime, $size);

        $this->assertSame($expectedEtag, $etag);
    }

    #[Test]
    public function last_modified_header_format(): void
    {
        $file = $this->tempDir . '/lastmod.txt';
        file_put_contents($file, 'last modified');

        $response = $this->handler->handle(new ServerRequest('GET', '/lastmod.txt'));

        $lastModified = $response->getHeaderLine('Last-Modified');

        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $lastModified);
    }

    #[Test]
    public function content_length_matches_body(): void
    {
        $content = 'exact content length';
        $file = $this->tempDir . '/length.txt';
        file_put_contents($file, $content);

        $response = $this->handler->handle(new ServerRequest('GET', '/length.txt'));

        $this->assertSame((string) strlen($content), $response->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function cache_control_header_present(): void
    {
        $file = $this->tempDir . '/cached.txt';
        file_put_contents($file, 'cached');

        $response = $this->handler->handle(new ServerRequest('GET', '/cached.txt'));

        $this->assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function serves_file_twice_from_cache(): void
    {
        $file = $this->tempDir . '/twice.txt';
        file_put_contents($file, 'cached twice');

        $response1 = $this->handler->handle(new ServerRequest('GET', '/twice.txt'));
        $response2 = $this->handler->handle(new ServerRequest('GET', '/twice.txt'));

        $this->assertSame(200, $response1->getStatusCode());
        $this->assertSame(200, $response2->getStatusCode());
        $this->assertSame('cached twice', (string) $response2->getBody());

        $stats = $this->handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);
    }

    #[Test]
    public function stream_file_contains_last_modified(): void
    {
        $file = $this->tempDir . '/streamed.bin';
        file_put_contents($file, str_repeat('x', 2 * 1024 * 1024));

        $response = $this->handler->handle(new ServerRequest('GET', '/streamed.bin'));

        $this->assertTrue($response->hasHeader('Last-Modified'));
        $this->assertTrue($response->hasHeader('ETag'));
        $this->assertTrue($response->hasHeader('Cache-Control'));
    }

    #[Test]
    public function handles_uppercase_extension(): void
    {
        $file = $this->tempDir . '/upper.HTML';
        file_put_contents($file, '<html>');

        $response = $this->handler->handle(new ServerRequest('GET', '/upper.HTML'));

        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function get_cache_stats_max_size(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 2048);

        $stats = $handler->getCacheStats();

        $this->assertSame(2048, $stats['max_size']);
    }

    #[Test]
    public function get_cache_stats_max_files_default(): void
    {
        $handler = new StaticFileHandler($this->tempDir, true, 1048576, 42);

        $stats = $handler->getCacheStats();

        $this->assertSame(42, $stats['max_files']);
    }

    #[Test]
    public function clear_cache_and_re_serve_caches_again(): void
    {
        $file = $this->tempDir . '/recache.txt';
        file_put_contents($file, 'recache');

        $this->handler->handle(new ServerRequest('GET', '/recache.txt'));
        $this->handler->clearCache();

        $stats = $this->handler->getCacheStats();
        $this->assertSame(0, $stats['entries']);

        $this->handler->handle(new ServerRequest('GET', '/recache.txt'));

        $stats = $this->handler->getCacheStats();
        $this->assertSame(1, $stats['entries']);
    }

    #[Test]
    public function nested_subdirectory_file_served(): void
    {
        mkdir($this->tempDir . '/sub/deep', 0777, true);
        $file = $this->tempDir . '/sub/deep/file.txt';
        file_put_contents($file, 'deeply nested');

        $response = $this->handler->handle(new ServerRequest('GET', '/sub/deep/file.txt'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('deeply nested', (string) $response->getBody());
    }

    #[Test]
    public function if_none_match_different_etag_returns_200(): void
    {
        $file = $this->tempDir . '/etagdiff.txt';
        file_put_contents($file, 'content');

        $request = (new ServerRequest('GET', '/etagdiff.txt'))
            ->withHeader('If-None-Match', '"wrong-etag"');

        $response = $this->handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = array_diff(scandir($dir), ['.', '..']);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
