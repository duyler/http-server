<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Socket;
use Throwable;

#[CoversClass(Server::class)]
class ServerSocketResourceTest extends TestCase
{
    private Server $server;

    #[Override]
    protected function tearDown(): void
    {
        if (isset($this->server)) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testReturnsNullWhenNotStarted(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    public function testReturnsSocketResourceInStandaloneMode(): void
    {
        $config = new ServerConfig(port: 18080);
        $this->server = new Server($config);

        $this->server->start();

        $resource = $this->server->getSocketResource();

        $this->assertTrue(
            $resource instanceof Socket || is_resource($resource),
            'Resource should be Socket or stream resource',
        );
    }

    public function testReturnsNullAfterStop(): void
    {
        $config = new ServerConfig(port: 18081);
        $this->server = new Server($config);

        $this->server->start();
        $this->server->stop();

        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    public function testReturnsExternalResourceInWorkerPoolMode(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->setWorkerId(1);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $this->server->setExternalSocketResource($socket);

        $resource = $this->server->getSocketResource();

        $this->assertSame($socket, $resource);

        socket_close($socket);
    }

    public function testReturnsNullInWorkerPoolModeWithoutExternalResource(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->setWorkerId(1);

        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    public function testStandaloneTakesPriorityOverExternalResource(): void
    {
        $config = new ServerConfig(port: 18082);
        $this->server = new Server($config);

        $this->server->start();

        $externalSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($externalSocket);

        $this->server->setExternalSocketResource($externalSocket);

        $internalResource = $this->server->getSocketResource();
        $this->assertNotSame($externalSocket, $internalResource);

        $this->server->stop();

        socket_close($externalSocket);
    }

    public function testSetExternalSocketResourceStoresResource(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $this->server->setWorkerId(1);
        $this->server->setExternalSocketResource($socket);

        $this->assertSame($socket, $this->server->getSocketResource());

        socket_close($socket);
    }

    public function testSetExternalSocketResourceAcceptsStreamResource(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $stream = fopen('php://memory', 'r');
        $this->assertIsResource($stream);

        $this->server->setExternalSocketResource($stream);

        $resource = $this->server->getSocketResource();
        $this->assertSame($stream, $resource);

        fclose($stream);
    }

    public function testSetExternalSocketResourceCanBeCalledMultipleTimes(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $socket1 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $socket2 = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket1);
        $this->assertNotFalse($socket2);

        $this->server->setExternalSocketResource($socket1);
        $this->assertSame($socket1, $this->server->getSocketResource());

        $this->server->setExternalSocketResource($socket2);
        $this->assertSame($socket2, $this->server->getSocketResource());

        socket_close($socket1);
        socket_close($socket2);
    }

    public function testSetExternalSocketResourceAcceptsNull(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);

        $this->server->setExternalSocketResource($socket);
        $this->assertNotNull($this->server->getSocketResource());

        $this->server->setExternalSocketResource(null);
        $this->assertNull($this->server->getSocketResource());

        socket_close($socket);
    }

    public function testSslServerReturnsStreamResource(): void
    {
        $certPath = sys_get_temp_dir() . '/test_' . uniqid() . '.pem';
        $this->generateTestCertificate($certPath);

        $config = new ServerConfig(
            port: 18443,
            ssl: true,
            sslCert: $certPath,
            sslKey: $certPath,
        );

        $this->server = new Server($config);
        $started = $this->server->start();

        if ($started) {
            $resource = $this->server->getSocketResource();
            $this->assertNotNull($resource);
            $this->assertIsNotSocket($resource);
        }

        if (file_exists($certPath)) {
            unlink($certPath);
        }
    }

    private function generateTestCertificate(string $path): void
    {
        $dn = [
            'commonName' => 'localhost',
        ];

        $privkey = openssl_pkey_new(['private_key_bits' => 2048]);
        assert(false !== $privkey);

        $csr = openssl_csr_new($dn, $privkey);
        assert(false !== $csr);

        $cert = openssl_csr_sign($csr, null, $privkey, 365);
        assert(false !== $cert);

        $certExported = openssl_x509_export_to_file($cert, $path);
        assert(false !== $certExported);

        $keyExported = openssl_pkey_export_to_file($privkey, $path . '.key');
        assert(false !== $keyExported);

        $certContent = file_get_contents($path);
        $keyContent = file_get_contents($path . '.key');
        assert(false !== $certContent);
        assert(false !== $keyContent);

        file_put_contents($path, $certContent . $keyContent);
        if (file_exists($path . '.key')) {
            unlink($path . '.key');
        }
    }

    private function assertIsNotSocket(mixed $value): void
    {
        $this->assertFalse(
            $value instanceof Socket,
            'Value should not be a Socket object for SSL',
        );
    }
}
