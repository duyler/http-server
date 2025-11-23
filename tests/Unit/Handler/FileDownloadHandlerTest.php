<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Handler;

use Duyler\HttpServer\Handler\FileDownloadHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FileDownloadHandlerTest extends TestCase
{
    private FileDownloadHandler $handler;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->handler = new FileDownloadHandler();
        $this->tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($this->tempFile, 'test content for download');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    #[Test]
    public function downloads_file(): void
    {
        $response = $this->handler->download($this->tempFile);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        $this->assertTrue($response->hasHeader('Content-Length'));
        $this->assertTrue($response->hasHeader('Content-Type'));
    }

    #[Test]
    public function sets_custom_filename(): void
    {
        $response = $this->handler->download($this->tempFile, 'custom.txt');

        $this->assertStringContainsString('custom.txt', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function sets_custom_mime_type(): void
    {
        $response = $this->handler->download($this->tempFile, null, 'application/custom');

        $this->assertSame('application/custom', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function returns_404_for_non_existent_file(): void
    {
        $response = $this->handler->download('/non/existent/file.txt');

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function supports_range_requests(): void
    {
        $response = $this->handler->download($this->tempFile);

        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    #[Test]
    public function downloads_file_range(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, 0, 4);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('test ', (string) $response->getBody());
        $this->assertStringContainsString('bytes 0-4', $response->getHeaderLine('Content-Range'));
    }

    #[Test]
    public function returns_416_for_invalid_range(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, 1000, 2000);

        $this->assertSame(416, $response->getStatusCode());
    }

    #[Test]
    public function parses_range_header(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-49', $fileSize);

        $this->assertSame(['start' => 0, 'end' => 49], $range);
    }

    #[Test]
    public function parses_open_ended_range(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-', $fileSize);

        $this->assertSame(['start' => 50, 'end' => 99], $range);
    }

    #[Test]
    public function returns_null_for_invalid_range_header(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('invalid', $fileSize);

        $this->assertNull($range);
    }
}
