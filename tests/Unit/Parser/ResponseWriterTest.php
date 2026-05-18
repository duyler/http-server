<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Parser;

use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Security\SecurityHeadersService;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\TestCase;

class ResponseWriterTest extends TestCase
{
    private ResponseWriter $writer;

    #[Override]
    protected function setUp(): void
    {
        $this->writer = new ResponseWriter();
    }

    public function testWritesSimpleResponse(): void
    {
        $response = new Response(200, [], 'Hello World');

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $output);
        $this->assertStringContainsString('Hello World', $output);
    }

    public function testWritesStatusCodeAndPhrase(): void
    {
        $response = new Response(404);

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 404 Not Found', $output);
    }

    public function testWritesCustomStatusPhrase(): void
    {
        $response = new Response(200, [], null, '1.1', 'Custom Phrase');

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 200 Custom Phrase', $output);
    }

    public function testWritesHeaders(): void
    {
        $response = (new Response(200))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('X-Custom', 'value');

        $output = $this->writer->write($response);

        $this->assertStringContainsString('Content-Type: application/json', $output);
        $this->assertStringContainsString('X-Custom: value', $output);
    }

    public function testWritesMultipleHeaderValues(): void
    {
        $response = (new Response(200))
            ->withHeader('Set-Cookie', ['cookie1=value1', 'cookie2=value2']);

        $output = $this->writer->write($response);

        $this->assertStringContainsString('Set-Cookie: cookie1=value1', $output);
        $this->assertStringContainsString('Set-Cookie: cookie2=value2', $output);
    }

    public function testWritesResponseWithBody(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/plain'], 'Response body');

        $output = $this->writer->write($response);

        $this->assertStringEndsWith("Response body", $output);
    }

    public function testSeparatesHeadersAndBodyWithDoubleCrlf(): void
    {
        $response = new Response(200, [], 'Body');

        $output = $this->writer->write($response);

        $this->assertStringContainsString("\r\n\r\nBody", $output);
    }

    public function testWritesEmptyBody(): void
    {
        $response = new Response(204);

        $output = $this->writer->write($response);

        $this->assertStringContainsString('HTTP/1.1 204 No Content', $output);
        $this->assertStringEndsWith("\r\n\r\n", $output);
    }

    public function testUsesCorrectHttpVersion(): void
    {
        $response = new Response(200, [], null, '1.0');

        $output = $this->writer->write($response);

        $this->assertStringStartsWith('HTTP/1.0', $output);
    }

    public function testWriteBufferedSmallResponseSingleCall(): void
    {
        $response = new Response(200, [], 'Small body');

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 8192);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $chunks[0]);
        $this->assertStringContainsString('Small body', $chunks[0]);
    }

    public function testWriteBufferedLargeResponseMultipleCalls(): void
    {
        $largeBody = str_repeat('X', 20000);
        $response = new Response(200, [], $largeBody);

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 8192);

        $this->assertGreaterThan(1, count($chunks));

        $fullOutput = implode('', $chunks);
        $this->assertStringContainsString('HTTP/1.1 200 OK', $fullOutput);
        $this->assertStringContainsString($largeBody, $fullOutput);
    }

    public function testWriteBufferedRespectsBufferSize(): void
    {
        $body = str_repeat('A', 10000);
        $response = new Response(200, [], $body);

        $chunks = [];
        $bufferSize = 4096;

        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, $bufferSize);

        foreach ($chunks as $index => $chunk) {
            if ($index < count($chunks) - 1) {
                $this->assertLessThanOrEqual($bufferSize, strlen($chunk));
            }
        }
    }

    public function testWriteBufferedEmptyBody(): void
    {
        $response = new Response(204);

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('HTTP/1.1 204 No Content', $chunks[0]);
    }

    public function testWriteBufferedWithHeaders(): void
    {
        $response = (new Response(200, ['Content-Type' => 'text/plain'], str_repeat('X', 10000)));

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 4096);

        $fullOutput = implode('', $chunks);
        $this->assertStringContainsString('Content-Type: text/plain', $fullOutput);
    }

    public function testWriteBufferedMinimizesChunks(): void
    {
        $body = str_repeat('B', 16000);
        $response = new Response(200, [], $body);

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 8192);

        $this->assertLessThanOrEqual(3, count($chunks), 'Should minimize number of chunks');
    }

    public function testWriteBufferedExactBufferSize(): void
    {
        $body = str_repeat('C', 8000);
        $response = new Response(200, [], $body);

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 8192);

        $fullOutput = implode('', $chunks);
        $this->assertStringContainsString($body, $fullOutput);
    }

    public function testAppliesSecurityHeadersWhenServiceSet(): void
    {
        $securityService = new SecurityHeadersService();
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $output);
        $this->assertStringContainsString('X-Frame-Options: DENY', $output);
        $this->assertStringContainsString('X-XSS-Protection: 1; mode=block', $output);
        $this->assertStringContainsString('Referrer-Policy: strict-origin-when-cross-origin', $output);
    }

    public function testDoesNotApplySecurityHeadersWhenServiceNotSet(): void
    {
        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringNotContainsString('X-Content-Type-Options', $output);
        $this->assertStringNotContainsString('X-Frame-Options', $output);
        $this->assertStringNotContainsString('X-XSS-Protection', $output);
    }

    public function testWriteChunkedAppliesSecurityHeaders(): void
    {
        $securityService = new SecurityHeadersService();
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200, [], 'Test body');
        $output = '';

        $this->writer->writeChunked($response, function (string $chunk) use (&$output): void {
            $output .= $chunk;
        });

        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $output);
        $this->assertStringContainsString('X-Frame-Options: DENY', $output);
    }

    public function testWriteBufferedAppliesSecurityHeaders(): void
    {
        $securityService = new SecurityHeadersService();
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200, [], 'Test body');
        $output = '';

        $this->writer->writeBuffered($response, function (string $chunk) use (&$output): void {
            $output .= $chunk;
        });

        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $output);
        $this->assertStringContainsString('X-Frame-Options: DENY', $output);
    }

    public function testDoesNotOverwriteExistingSecurityHeaders(): void
    {
        $securityService = new SecurityHeadersService();
        $this->writer->setSecurityHeadersService($securityService);

        $response = (new Response(200))->withHeader('X-Frame-Options', 'SAMEORIGIN');
        $output = $this->writer->write($response);

        $this->assertStringContainsString('X-Frame-Options: SAMEORIGIN', $output);
        $this->assertStringNotContainsString('X-Frame-Options: DENY', $output);
    }

    public function testCustomSecurityHeadersFromService(): void
    {
        $securityService = new SecurityHeadersService(
            frameOptions: 'SAMEORIGIN',
            referrerPolicy: 'no-referrer',
        );
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringContainsString('X-Frame-Options: SAMEORIGIN', $output);
        $this->assertStringContainsString('Referrer-Policy: no-referrer', $output);
    }

    public function testHstsHeaderWhenEnabled(): void
    {
        $securityService = new SecurityHeadersService(enableHsts: true);
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringContainsString('Strict-Transport-Security: max-age=31536000; includeSubDomains', $output);
    }

    public function testNoHstsHeaderWhenDisabled(): void
    {
        $securityService = new SecurityHeadersService(enableHsts: false);
        $this->writer->setSecurityHeadersService($securityService);

        $response = new Response(200);
        $output = $this->writer->write($response);

        $this->assertStringNotContainsString('Strict-Transport-Security', $output);
    }
}
