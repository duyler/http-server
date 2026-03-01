<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Handler;

use Duyler\HttpServer\Handler\FileDownloadHandler;
use Override;
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

    public function testDownloadsFile(): void
    {
        $response = $this->handler->download($this->tempFile);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        $this->assertTrue($response->hasHeader('Content-Length'));
        $this->assertTrue($response->hasHeader('Content-Type'));
    }

    public function testSetsCustomFilename(): void
    {
        $response = $this->handler->download($this->tempFile, 'custom.txt');

        $this->assertStringContainsString('custom.txt', $response->getHeaderLine('Content-Disposition'));
    }

    public function testSetsCustomMimeType(): void
    {
        $response = $this->handler->download($this->tempFile, null, 'application/custom');

        $this->assertSame('application/custom', $response->getHeaderLine('Content-Type'));
    }

    public function testReturns404ForNonExistentFile(): void
    {
        $response = $this->handler->download('/non/existent/file.txt');

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSupportsRangeRequests(): void
    {
        $response = $this->handler->download($this->tempFile);

        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    public function testDownloadsFileRange(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, 0, 4);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('test ', (string) $response->getBody());
        $this->assertStringContainsString('bytes 0-4', $response->getHeaderLine('Content-Range'));
    }

    public function testReturns416ForInvalidRange(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, 1000, 2000);

        $this->assertSame(416, $response->getStatusCode());
    }

    public function testParsesRangeHeader(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-49', $fileSize);

        $this->assertSame([['start' => 0, 'end' => 49]], $range);
    }

    public function testParsesOpenEndedRange(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-', $fileSize);

        $this->assertSame([['start' => 50, 'end' => 99]], $range);
    }

    public function testReturnsNullForInvalidRangeHeader(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('invalid', $fileSize);

        $this->assertNull($range);
    }

    public function testParsesSuffixRange(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=-10', $fileSize);

        $this->assertSame([['start' => 90, 'end' => 99]], $range);
    }

    public function testParsesMultipleRanges(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-9,20-29,50-59', $fileSize);

        $this->assertSame([
            ['start' => 0, 'end' => 9],
            ['start' => 20, 'end' => 29],
            ['start' => 50, 'end' => 59],
        ], $range);
    }

    public function testRejectsMoreThan10Ranges(): void
    {
        $fileSize = 1000;
        $rangeHeader = 'bytes=' . implode(',', array_map(fn(int $i): string => "$i-" . ($i + 9), range(0, 100, 10)));

        $range = $this->handler->parseRangeHeader($rangeHeader, $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsOverflowStartValue(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=99999999999999999999-', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsOverflowEndValue(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-99999999999999999999', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsNegativeStartValue(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=-10-50', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsNegativeEndValue(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0--10', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsNonNumericValue(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=abc-50', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsRangeWithoutBytesPrefix(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('0-49', $fileSize);

        $this->assertNull($range);
    }

    public function testSkipsInvalidRangeInMultiRange(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=0-9,200-299,50-59', $fileSize);

        $this->assertSame([
            ['start' => 0, 'end' => 9],
            ['start' => 50, 'end' => 59],
        ], $range);
    }

    public function testReturnsNullWhenAllRangesInvalid(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=200-299,300-399', $fileSize);

        $this->assertNull($range);
    }

    public function testHandlesSuffixRangeLargerThanFile(): void
    {
        $fileSize = 50;

        $range = $this->handler->parseRangeHeader('bytes=-100', $fileSize);

        $this->assertSame([['start' => 0, 'end' => 49]], $range);
    }

    public function testClampsEndToFileSize(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-200', $fileSize);

        $this->assertSame([['start' => 50, 'end' => 99]], $range);
    }

    public function testRejectsEmptyRangeParts(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=-', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsRangeWithOnlyStartEqualsFileSize(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=100-', $fileSize);

        $this->assertNull($range);
    }

    public function testRejectsRangeStartGreaterThanEnd(): void
    {
        $fileSize = 100;

        $range = $this->handler->parseRangeHeader('bytes=50-10', $fileSize);

        $this->assertNull($range);
    }

    public function testHandlesLargeValidRangeValue(): void
    {
        $fileSize = PHP_INT_MAX;

        $range = $this->handler->parseRangeHeader('bytes=0-999999999999999999', $fileSize);

        $this->assertSame([['start' => 0, 'end' => 999999999999999999]], $range);
    }

    public function testReturns404ForNonExistentFileInRangeDownload(): void
    {
        $response = $this->handler->downloadRange('/non/existent/file.txt', 0, 10);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testReturns416ForNegativeStartInRange(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, -1, 10);

        $this->assertSame(416, $response->getStatusCode());
    }

    public function testReturns416ForStartGreaterThanEndInRange(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 10, 5);

        $this->assertSame(416, $response->getStatusCode());
    }

    public function testReturns416ForStartAtFileSize(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, $fileSize, $fileSize + 10);

        $this->assertSame(416, $response->getStatusCode());
    }

    public function testDownloadsFullRangeFromStart(): void
    {
        $fileSize = filesize($this->tempFile);

        $response = $this->handler->downloadRange($this->tempFile, 0, $fileSize - 1);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('test content for download', (string) $response->getBody());
    }

    public function testSetsCustomFilenameInRangeDownload(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 0, 4, 'custom.txt');

        $this->assertStringContainsString('custom.txt', $response->getHeaderLine('Content-Disposition'));
    }

    public function testSetsCustomMimeTypeInRangeDownload(): void
    {
        $response = $this->handler->downloadRange($this->tempFile, 0, 4, null, 'application/custom');

        $this->assertSame('application/custom', $response->getHeaderLine('Content-Type'));
    }

    public function testDetectsPdfMimeType(): void
    {
        $tempPdf = tempnam(sys_get_temp_dir(), 'test_') . '.pdf';
        file_put_contents($tempPdf, '%PDF-1.4');

        $response = $this->handler->download($tempPdf);

        unlink($tempPdf);

        $this->assertStringContainsString('application/pdf', $response->getHeaderLine('Content-Type'));
    }

    public function testDetectsJsonMimeType(): void
    {
        $tempJson = tempnam(sys_get_temp_dir(), 'test_') . '.json';
        file_put_contents($tempJson, '{}');

        $response = $this->handler->download($tempJson);

        unlink($tempJson);

        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testDefaultsToOctetStreamForUnknownExtension(): void
    {
        $tempUnknown = tempnam(sys_get_temp_dir(), 'test_') . '.xyz123';
        file_put_contents($tempUnknown, chr(0) . chr(1) . chr(2) . chr(3));

        $response = $this->handler->download($tempUnknown);

        unlink($tempUnknown);

        $this->assertStringContainsString('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }
}
