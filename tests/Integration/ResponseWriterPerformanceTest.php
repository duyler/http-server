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
