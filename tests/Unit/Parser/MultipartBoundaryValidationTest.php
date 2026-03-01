<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Upload\TempFileManager;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\TestCase;

class MultipartBoundaryValidationTest extends TestCase
{
    private RequestParser $parser;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $this->parser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
    }

    public function testAcceptsValidBoundary(): void
    {
        $boundary = 'boundary123';
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testIgnoresEmptyBoundary(): void
    {
        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: multipart/form-data; boundary=\r\n";
        $request .= "\r\n";
        $request .= "some body content";

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertNull($parsed->getParsedBody());
    }

    public function testRejectsBoundaryTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid multipart boundary');

        $boundary = str_repeat('a', 71);
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');
        $this->parser->parse($request, '127.0.0.1', 8080);
    }

    public function testAcceptsBoundaryMaxLength(): void
    {
        $boundary = str_repeat('a', 70);
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testRejectsBoundaryWithInvalidCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid multipart boundary');

        $boundary = 'boundary<script>';
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');
        $this->parser->parse($request, '127.0.0.1', 8080);
    }

    public function testAcceptsBoundaryWithAllowedSpecialChars(): void
    {
        $boundary = "boundary-_.'()+,/:=?";
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testRejectsBoundaryEndingWithSpace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid multipart boundary');

        $boundary = 'boundary ';
        $request = $this->createQuotedMultipartRequest($boundary, 'field1', 'value1');
        $this->parser->parse($request, '127.0.0.1', 8080);
    }

    public function testAcceptsQuotedBoundaryWithSpaces(): void
    {
        $boundary = 'boundary part 2';
        $request = $this->createQuotedMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testAcceptsBoundaryWithNumbers(): void
    {
        $boundary = 'boundary1234567890';
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testAcceptsBoundaryWithQuotes(): void
    {
        $boundary = "bound'ary";
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testRejectsBoundaryWithBackslash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid multipart boundary');

        $boundary = 'boundary\\test';
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');
        $this->parser->parse($request, '127.0.0.1', 8080);
    }

    public function testStripsQuotesFromBoundary(): void
    {
        $boundary = 'boundary123';
        $quotedBoundary = '"boundary123"';

        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: multipart/form-data; boundary={$quotedBoundary}\r\n";
        $request .= "\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"field1\"\r\n";
        $request .= "\r\n";
        $request .= "value1\r\n";
        $request .= "--{$boundary}--";

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    public function testAcceptsTypicalBrowserBoundary(): void
    {
        $boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
        $request = $this->createMultipartRequest($boundary, 'field1', 'value1');

        $parsed = $this->parser->parse($request, '127.0.0.1', 8080);

        $this->assertSame(['field1' => 'value1'], $parsed->getParsedBody());
    }

    private function createMultipartRequest(string $boundary, string $fieldName, string $fieldValue): string
    {
        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
        $request .= "\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"{$fieldName}\"\r\n";
        $request .= "\r\n";
        $request .= "{$fieldValue}\r\n";
        $request .= "--{$boundary}--";

        return $request;
    }

    private function createQuotedMultipartRequest(string $boundary, string $fieldName, string $fieldValue): string
    {
        $request = "POST / HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: multipart/form-data; boundary=\"{$boundary}\"\r\n";
        $request .= "\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"{$fieldName}\"\r\n";
        $request .= "\r\n";
        $request .= "{$fieldValue}\r\n";
        $request .= "--{$boundary}--";

        return $request;
    }
}
