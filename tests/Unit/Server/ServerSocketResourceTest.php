<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function returns_null_when_not_started(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function returns_socket_resource_in_standalone_mode(): void
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

    #[Test]
    public function returns_null_after_stop(): void
    {
        $config = new ServerConfig(port: 18081);
        $this->server = new Server($config);

        $this->server->start();
        $this->server->stop();

        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function returns_external_resource_in_worker_pool_mode(): void
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

    #[Test]
    public function returns_null_in_worker_pool_mode_without_external_resource(): void
    {
        $config = new ServerConfig();
        $this->server = new Server($config);

        $this->server->setWorkerId(1);

        $resource = $this->server->getSocketResource();

        $this->assertNull($resource);
    }

    #[Test]
    public function standalone_takes_priority_over_external_resource(): void
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

    #[Test]
    public function set_external_socket_resource_stores_resource(): void
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

    #[Test]
    public function set_external_socket_resource_accepts_stream_resource(): void
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

    #[Test]
    public function set_external_socket_resource_can_be_called_multiple_times(): void
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

    #[Test]
    public function set_external_socket_resource_accepts_null(): void
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

    #[Test]
    public function ssl_server_returns_stream_resource(): void
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
