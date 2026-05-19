<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Security;

use Duyler\HttpServer\Handler\StaticFileHandler;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StaticFileHandler::class)]
class PathTraversalTest extends TestCase
{
    private string $publicDir;
    private StaticFileHandler $handler;

    #[Override]
    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/duyler_path_traversal_test_' . uniqid();
        mkdir($this->publicDir, 0755, true);
        mkdir($this->publicDir . '/subdir', 0755, true);
        file_put_contents($this->publicDir . '/index.html', '<html>safe content</html>');
        file_put_contents($this->publicDir . '/subdir/nested.txt', 'nested file content');

        $this->handler = new StaticFileHandler(
            publicPath: $this->publicDir,
            enableCache: false,
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        $files = [
            $this->publicDir . '/subdir/nested.txt',
            $this->publicDir . '/index.html',
        ];
        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->publicDir . '/subdir')) {
            rmdir($this->publicDir . '/subdir');
        }
        if (is_dir($this->publicDir)) {
            rmdir($this->publicDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function normal_file_access_works(): void
    {
        $request = new ServerRequest('GET', '/index.html');
        $this->assertTrue($this->handler->isStaticFile($request));

        $response = $this->handler->handle($request);
        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('safe content', (string) $response->getBody());
    }

    #[Test]
    public function parent_directory_traversal_returns_null(): void
    {
        $request = new ServerRequest('GET', '/../etc/passwd');

        $this->assertFalse($this->handler->isStaticFile($request));

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function double_encoded_traversal_returns_null(): void
    {
        $request = new ServerRequest('GET', '/%2e%2e%2fetc%2fpasswd');

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function null_byte_injection_returns_null(): void
    {
        $uri = '/' . urlencode("../../../etc/passwd\x00.html");
        $request = new ServerRequest('GET', $uri);

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function traversal_with_query_string_returns_null(): void
    {
        $request = new ServerRequest('GET', '/../../../etc/passwd?foo=bar');

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function mixed_case_traversal_returns_null(): void
    {
        $request = new ServerRequest('GET', '/..%2F..%2Fetc%2Fpasswd');

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function repeated_dots_traversal_returns_null(): void
    {
        $request = new ServerRequest('GET', '/....//....//etc/passwd');

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function nested_traversal_returns_null(): void
    {
        $request = new ServerRequest('GET', '/subdir/../../etc/passwd');

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function backslash_traversal_returns_null(): void
    {
        $request = new ServerRequest('GET', '/..\\..\\etc\\passwd');

        $response = $this->handler->handle($request);
        $this->assertNull($response);
    }

    #[Test]
    public function traversal_does_not_expose_file_contents(): void
    {
        $maliciousPaths = [
            '/../etc/passwd',
            '/%2e%2e/etc/passwd',
            '/..%2f..%2fetc%2fpasswd',
        ];

        foreach ($maliciousPaths as $path) {
            $request = new ServerRequest('GET', $path);
            $response = $this->handler->handle($request);
            $this->assertNull($response, "Path traversal with {$path} should return null");
        }
    }
}
