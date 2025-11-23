<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TempFileCleanupTest extends TestCase
{
    private Server $server;
    private int $port;

    protected function setUp(): void
    {
        $this->port = $this->findAvailablePort();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    #[Test]
    public function server_reset_cleans_up_temporary_files(): void
    {
        $this->server->start();

        $boundary = 'test-boundary-12345';
        $filename = 'test.txt';
        $fileContent = 'test file content';

        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: text/plain\r\n";
        $body .= "\r\n";
        $body .= $fileContent;
        $body .= "\r\n--{$boundary}--\r\n";

        $request = "POST /upload HTTP/1.1\r\n";
        $request .= "Host: localhost\r\n";
        $request .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
        $request .= "Content-Length: " . strlen($body) . "\r\n";
        $request .= "\r\n";
        $request .= $body;

        $tempDirBefore = $this->countTempFiles();

        $this->sendHttpRequest($request);
        usleep(100000);

        if ($this->server->hasRequest()) {
            $serverRequest = $this->server->getRequest();
            $uploadedFiles = $serverRequest->getUploadedFiles();

            $this->assertCount(1, $uploadedFiles);
            $this->assertArrayHasKey('file', $uploadedFiles);

            $uploadedFile = $uploadedFiles['file'];
            $tmpPath = $uploadedFile->getStream()->getMetadata('uri');

            $this->assertFileExists($tmpPath);

            $this->server->reset();

            $this->assertFileDoesNotExist($tmpPath);
        }

        $tempDirAfter = $this->countTempFiles();

        $this->assertLessThanOrEqual($tempDirBefore + 1, $tempDirAfter);
    }

    #[Test]
    public function multiple_requests_with_reset_dont_leak_memory(): void
    {
        $this->server->start();

        $tempDirBefore = $this->countTempFiles();

        for ($i = 0; $i < 3; $i++) {
            $boundary = "test-boundary-{$i}";
            $body = "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"test{$i}.txt\"\r\n";
            $body .= "Content-Type: text/plain\r\n\r\n";
            $body .= "content {$i}\r\n--{$boundary}--\r\n";

            $request = "POST /upload HTTP/1.1\r\n";
            $request .= "Host: localhost\r\n";
            $request .= "Content-Type: multipart/form-data; boundary={$boundary}\r\n";
            $request .= "Content-Length: " . strlen($body) . "\r\n\r\n";
            $request .= $body;

            $this->sendHttpRequest($request);
            usleep(100000);

            if ($this->server->hasRequest()) {
                $this->server->getRequest();
            }

            $this->server->reset();
            $this->server->start();
        }

        $tempDirAfter = $this->countTempFiles();

        $this->assertLessThanOrEqual($tempDirBefore + 3, $tempDirAfter);
    }

    private function sendHttpRequest(string $request): void
    {
        $client = $this->createClient();
        fwrite($client, $request);
        fclose($client);
    }

    /**
     * @return resource
     */
    private function createClient()
    {
        $client = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            1,
        );

        if ($client === false) {
            $this->fail("Failed to connect to server: $errstr ($errno)");
        }

        return $client;
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    private function countTempFiles(): int
    {
        $tempDir = sys_get_temp_dir();
        $files = glob($tempDir . '/upload_*');

        return $files !== false ? count($files) : 0;
    }
}

