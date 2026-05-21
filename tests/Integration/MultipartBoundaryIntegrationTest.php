<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Upload\TempFileManager;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MultipartBoundaryIntegrationTest extends TestCase
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

    #[Test]
    public function full_request_with_valid_boundary(): void
    {
        $boundary = '----WebKitFormBoundary7MA4YWxkTrZu0gW';
        $request = $this->createFullMultipartRequest($boundary);

        $parsed = $this->parser->parse($request, '192.168.1.100', 54321);

        $this->assertSame('POST', $parsed->getMethod());
        $parsedBody = $parsed->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertSame('John Doe', $parsedBody['name']);
        $this->assertSame('john@example.com', $parsedBody['email']);
    }

    #[Test]
    public function full_request_with_malicious_boundary_in_headers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid multipart boundary');

        $boundary = '<script>alert("xss")</script>';
        $request = $this->createFullMultipartRequest($boundary);

        $this->parser->parse($request, '192.168.1.100', 54321);
    }

    #[Test]
    public function full_request_with_excessively_long_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid multipart boundary');

        $boundary = str_repeat('x', 71);
        $request = $this->createFullMultipartRequest($boundary);

        $this->parser->parse($request, '192.168.1.100', 54321);
    }

    #[Test]
    public function full_request_with_boundary_in_quotes(): void
    {
        $boundary = 'boundary with spaces';
        $request = $this->createQuotedMultipartRequest($boundary);

        $parsed = $this->parser->parse($request, '192.168.1.100', 54321);

        $parsedBody = $parsed->getParsedBody();
        $this->assertIsArray($parsedBody);
        $this->assertSame('test value', $parsedBody['field']);
    }

    #[Test]
    public function full_request_with_file_upload_and_valid_boundary(): void
    {
        $boundary = 'boundary-file-upload-123';
        $fileContent = 'This is a test file content';

        $request = "POST /upload HTTP/1.1\r\n";
        $request .= "Host: localhost:8080\r\n";
        $request .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
        $request .= "\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"file\"; filename=\"test.txt\"\r\n";
        $request .= "Content-Type: text/plain\r\n";
        $request .= "\r\n";
        $request .= "{$fileContent}\r\n";
        $request .= "--{$boundary}--";

        $parsed = $this->parser->parse($request, '192.168.1.100', 54321);

        $uploadedFiles = $parsed->getUploadedFiles();
        $this->assertCount(1, $uploadedFiles);
        $this->assertArrayHasKey('file', $uploadedFiles);
        $this->assertSame('test.txt', $uploadedFiles['file']->getClientFilename());
        $this->assertSame('text/plain', $uploadedFiles['file']->getClientMediaType());
    }

    private function createFullMultipartRequest(string $boundary): string
    {
        $request = "POST /api/users HTTP/1.1\r\n";
        $request .= "Host: example.com\r\n";
        $request .= "User-Agent: Mozilla/5.0\r\n";
        $request .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
        $request .= "\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"name\"\r\n";
        $request .= "\r\n";
        $request .= "John Doe\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"email\"\r\n";
        $request .= "\r\n";
        $request .= "john@example.com\r\n";
        $request .= "--{$boundary}--";

        return $request;
    }

    private function createQuotedMultipartRequest(string $boundary): string
    {
        $request = "POST /api/data HTTP/1.1\r\n";
        $request .= "Host: example.com\r\n";
        $request .= "Content-Type: multipart/form-data; boundary=\"{$boundary}\"\r\n";
        $request .= "\r\n";
        $request .= "--{$boundary}\r\n";
        $request .= "Content-Disposition: form-data; name=\"field\"\r\n";
        $request .= "\r\n";
        $request .= "test value\r\n";
        $request .= "--{$boundary}--";

        return $request;
    }
}
