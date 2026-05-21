<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Handler;

use Duyler\HttpServer\Handler\FileDownloadHandler;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FileDownloadHandlerTest extends TestCase
{
    private FileDownloadHandler $handler;
    private string $tempFile;

    #[Override]
    protected function setUp(): void
    {
        $this->handler = new FileDownloadHandler();
        $this->tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($this->tempFile, 'test content for download');
    }

    #[Override]
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
        $response = $this->handler->downloadRange($this->tempFile, 0, 4);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('test ', (string) $response->getBody());
        $this->assertStringContainsString('bytes 0-4', $response->getHeaderLine('Content-Range'));
    }

    #[Test]
    public function returns_416_for_invalid_range(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 1000, 2000);

        $this->assertSame(416, $response->getStatusCode());
    }

    #[Test]
    public function parses_range_header(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-49', $fileSize);

        $this->assertSame([['start' => 0, 'end' => 49]], $range);
    }

    #[Test]
    public function parses_open_ended_range(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-', $fileSize);

        $this->assertSame([['start' => 50, 'end' => 99]], $range);
    }

    #[Test]
    public function returns_null_for_invalid_range_header(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('invalid', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function parses_suffix_range(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=-10', $fileSize);

        $this->assertSame([['start' => 90, 'end' => 99]], $range);
    }

    #[Test]
    public function parses_multiple_ranges(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-9,20-29,50-59', $fileSize);

        $this->assertSame([
            ['start' => 0, 'end' => 9],
            ['start' => 20, 'end' => 29],
            ['start' => 50, 'end' => 59],
        ], $range);
    }

    #[Test]
    public function rejects_more_than_10_ranges(): void
    {
        $fileSize = 1000;
        $rangeHeader = 'bytes=' . implode(',', array_map(fn(int $i): string => "$i-" . ($i + 9), range(0, 100, 10)));

        $range = $this->handler->parseRangeHeader($rangeHeader, $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_overflow_start_value(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=99999999999999999999-', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_overflow_end_value(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-99999999999999999999', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_negative_start_value(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=-10-50', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_negative_end_value(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0--10', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_non_numeric_value(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=abc-50', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_range_without_bytes_prefix(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('0-49', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function skips_invalid_range_in_multi_range(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-9,200-299,50-59', $fileSize);

        $this->assertSame([
            ['start' => 0, 'end' => 9],
            ['start' => 50, 'end' => 59],
        ], $range);
    }

    #[Test]
    public function returns_null_when_all_ranges_invalid(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=200-299,300-399', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function handles_suffix_range_larger_than_file(): void
    {
        $fileSize = 50;

        $range = $this->handler->parseRangeHeader('bytes=-100', $fileSize);

        $this->assertSame([['start' => 0, 'end' => 49]], $range);
    }

    #[Test]
    public function clamps_end_to_file_size(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-200', $fileSize);

        $this->assertSame([['start' => 50, 'end' => 99]], $range);
    }

    #[Test]
    public function rejects_empty_range_parts(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=-', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_range_with_only_start_equals_file_size(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=100-', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function rejects_range_start_greater_than_end(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-10', $fileSize);

        $this->assertNull($range);
    }

    #[Test]
    public function handles_large_valid_range_value(): void
    {
        $fileSize = PHP_INT_MAX;

        $range = $this->handler->parseRangeHeader('bytes=0-999999999999999999', $fileSize);

        $this->assertSame([['start' => 0, 'end' => 999999999999999999]], $range);
    }

    #[Test]
    public function returns_404_for_non_existent_file_in_range_download(): void
    {
        $response = $this->handler->downloadRange('/non/existent/file.txt', 0, 10);

        $this->assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns_416_for_negative_start_in_range(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, -1, 10);

        $this->assertSame(416, $response->getStatusCode());
    }

    #[Test]
    public function returns_416_for_start_greater_than_end_in_range(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 10, 5);

        $this->assertSame(416, $response->getStatusCode());
    }

    #[Test]
    public function returns_416_for_start_at_file_size(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, $fileSize, $fileSize + 10);

        $this->assertSame(416, $response->getStatusCode());
    }

    #[Test]
    public function downloads_full_range_from_start(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, 0, $fileSize - 1);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('test content for download', (string) $response->getBody());
    }

    #[Test]
    public function sets_custom_filename_in_range_download(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 0, 4, 'custom.txt');

        $this->assertStringContainsString('custom.txt', $response->getHeaderLine('Content-Disposition'));
    }

    #[Test]
    public function sets_custom_mime_type_in_range_download(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 0, 4, null, 'application/custom');

        $this->assertSame('application/custom', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function detects_pdf_mime_type(): void
    {
        $tempPdf = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';
        file_put_contents($tempPdf, '%PDF-1.4');

        $response = $this->handler->download($tempPdf);

        unlink($tempPdf);

        $this->assertStringContainsString('application/pdf', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function detects_json_mime_type(): void
    {
        $tempJson = tempnam(sys_get_temp_dir(), 'test_') . '.json';
        file_put_contents($tempJson, '{}');

        $response = $this->handler->download($tempJson);

        unlink($tempJson);

        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function defaults_to_octet_stream_for_unknown_extension(): void
    {
        $tempUnknown = tempnam(sys_get_temp_dir(), 'test_') . '.xyz123';
        file_put_contents($tempUnknown, chr(0) . chr(1) . chr(2) . chr(3));

        $response = $this->handler->download($tempUnknown);

        unlink($tempUnknown);

        $this->assertStringContainsString('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }
}
