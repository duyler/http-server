<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Parser\ResponseWriter;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResponseWriterPerformanceTest extends TestCase
{
    private ResponseWriter $writer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new ResponseWriter();
    }

    #[Test]
    public function write_method_handles_large_response_efficiently(): void
    {
        $largeBody = str_repeat('Lorem ipsum dolor sit amet. ', 10000);
        $response = new Response(200, ['Content-Type' => 'text/plain'], $largeBody);

        $startMemory = memory_get_usage(true);
        $startTime = microtime(true);

        $output = $this->writer->write($response);

        $elapsed = microtime(true) - $startTime;
        $memoryUsed = memory_get_usage(true) - $startMemory;

        $this->assertStringContainsString('HTTP/1.1 200 OK', $output);
        $this->assertStringContainsString($largeBody, $output);
        $this->assertLessThan(1.0, $elapsed, 'Should complete within 1 second');
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed, 'Should use less than 5MB extra memory');
    }

    #[Test]
    public function write_buffered_reduces_memory_overhead(): void
    {
        $largeBody = str_repeat('X', 1024 * 1024);
        $response = new Response(200, [], $largeBody);

        $chunks = [];
        $startMemory = memory_get_usage(true);

        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, 8192);

        $peakMemory = memory_get_usage(true) - $startMemory;

        $this->assertGreaterThan(0, count($chunks));
        $this->assertLessThan(3 * 1024 * 1024, $peakMemory, 'Buffered write should use less memory');
    }

    #[Test]
    public function write_buffered_minimizes_callback_calls(): void
    {
        $body = str_repeat('A', 100000);
        $response = new Response(200, [], $body);

        $callCount = 0;
        $this->writer->writeBuffered($response, function () use (&$callCount): void {
            $callCount++;
        }, 32768);

        $expectedMaxCalls = ceil(strlen($body) / 32768) + 1;
        $this->assertLessThanOrEqual($expectedMaxCalls, $callCount, 'Should minimize callback calls');
    }

    #[Test]
    public function write_buffered_handles_many_headers_efficiently(): void
    {
        $response = new Response(200, [], 'Body');

        for ($i = 0; $i < 50; $i++) {
            $response = $response->withAddedHeader("X-Custom-{$i}", "value-{$i}");
        }

        $chunks = [];
        $startTime = microtime(true);

        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });

        $elapsed = microtime(true) - $startTime;

        $fullOutput = implode('', $chunks);
        $this->assertStringContainsString('X-Custom-0: value-0', $fullOutput);
        $this->assertStringContainsString('X-Custom-49: value-49', $fullOutput);
        $this->assertLessThan(0.1, $elapsed, 'Should handle many headers quickly');
    }

    #[Test]
    public function write_vs_write_buffered_consistency(): void
    {
        $body = str_repeat('Test content ', 1000);
        $response = new Response(200, ['Content-Type' => 'text/plain'], $body);

        $outputDirect = $this->writer->write($response);

        $chunks = [];
        $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        $outputBuffered = implode('', $chunks);

        $this->assertSame($outputDirect, $outputBuffered, 'Both methods should produce identical output');
    }

    #[Test]
    public function write_buffered_performance_with_varied_sizes(): void
    {
        $sizes = [1024, 8192, 65536, 1024 * 1024];

        foreach ($sizes as $size) {
            $body = str_repeat('X', $size);
            $response = new Response(200, [], $body);

            $startTime = microtime(true);
            $chunks = [];

            $this->writer->writeBuffered($response, function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            }, 8192);

            $elapsed = microtime(true) - $startTime;

            $fullOutput = implode('', $chunks);
            $this->assertStringContainsString($body, $fullOutput);
            $this->assertLessThan(1.0, $elapsed, "Should handle {$size} bytes efficiently");
        }
    }

    #[Test]
    public function write_method_optimization_with_many_parts(): void
    {
        $headers = [];
        for ($i = 0; $i < 20; $i++) {
            $headers["Header-{$i}"] = "value-{$i}";
        }

        $body = str_repeat('Body content ', 500);
        $response = new Response(200, $headers, $body);

        $startTime = microtime(true);
        $output = $this->writer->write($response);
        $elapsed = microtime(true) - $startTime;

        $this->assertStringContainsString('HTTP/1.1 200 OK', $output);
        $this->assertStringContainsString('Header-0: value-0', $output);
        $this->assertStringContainsString('Header-19: value-19', $output);
        $this->assertStringContainsString($body, $output);
        $this->assertLessThan(0.1, $elapsed, 'Optimized write should be fast');
    }
}
