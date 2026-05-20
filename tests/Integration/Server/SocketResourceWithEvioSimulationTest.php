<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Ev;
use EvIo;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;
use Throwable;

#[CoversClass(Server::class)]
#[Group('ev')]
class SocketResourceWithEvioSimulationTest extends TestCase
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
    public function socket_resource_works_with_evio(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $certPath = sys_get_temp_dir() . '/test_evio_ssl_' . uniqid() . '.pem';
        $this->generateTestCertificate($certPath);

        $config = new ServerConfig(
            port: 18083,
            ssl: true,
            sslCert: $certPath,
            sslKey: $certPath,
        );
        $this->server = new Server($config);
        $this->server->start();

        $resource = $this->server->getSocketResource();
        $this->assertNotNull($resource);
        $this->assertIsNotSocket($resource);

        $ioCallbackCalled = false;

        $ioWatcher = new EvIo(
            $resource,
            Ev::READ,
            function (EvIo $watcher, int $revents) use (&$ioCallbackCalled): void {
                $ioCallbackCalled = true;
                $watcher->stop();
                Ev::stop(Ev::BREAK_ALL);
            },
        );

        Ev::run(Ev::RUN_NOWAIT);

        $this->assertFalse($ioCallbackCalled, 'No data, callback should not be called');

        $ch = curl_init("https://127.0.0.1:18083/");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_exec($ch);

        $ioWatcher->start();
        Ev::run(Ev::RUN_NOWAIT);

        if (file_exists($certPath)) {
            unlink($certPath);
        }
    }

    #[Test]
    public function evio_can_be_created_with_server_resource(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $certPath = sys_get_temp_dir() . '/test_evio_ssl2_' . uniqid() . '.pem';
        $this->generateTestCertificate($certPath);

        $config = new ServerConfig(
            port: 18084,
            ssl: true,
            sslCert: $certPath,
            sslKey: $certPath,
        );
        $this->server = new Server($config);
        $this->server->start();

        $resource = $this->server->getSocketResource();
        $this->assertIsNotSocket($resource);

        $ioWatcher = new EvIo($resource, Ev::READ, function (): void {});

        $this->assertInstanceOf(EvIo::class, $ioWatcher);

        $ioWatcher->stop();

        if (file_exists($certPath)) {
            unlink($certPath);
        }
    }

    #[Test]
    public function external_socket_resource_works_with_evio(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $config = new ServerConfig();
        $this->server = new Server($config);
        $this->server->setWorkerId(1);

        $stream = stream_socket_server('tcp://127.0.0.1:18085', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        $this->assertNotFalse($stream, "Failed to create stream socket: $errstr");

        $this->server->setExternalSocketResource($stream);

        $resource = $this->server->getSocketResource();
        $this->assertSame($stream, $resource);

        $ioWatcher = new EvIo($resource, Ev::READ, function (): void {});

        $this->assertInstanceOf(EvIo::class, $ioWatcher);

        $ioWatcher->stop();
        fclose($stream);
    }

    #[Test]
    public function ssl_server_returns_stream_resource_for_evio(): void
    {
        if (!extension_loaded('ev')) {
            $this->markTestSkipped('ev extension not loaded');
        }

        $certPath = sys_get_temp_dir() . '/test_ssl_' . uniqid() . '.pem';
        $this->generateTestCertificate($certPath);

        $config = new ServerConfig(
            port: 18444,
            ssl: true,
            sslCert: $certPath,
            sslKey: $certPath,
        );

        $this->server = new Server($config);
        $started = $this->server->start();

        if (!$started) {
            $this->markTestSkipped('Failed to start SSL server');
        }

        $resource = $this->server->getSocketResource();
        $this->assertNotNull($resource);
        $this->assertIsNotSocket($resource);

        $ioWatcher = new EvIo($resource, Ev::READ, function (): void {});

        $this->assertInstanceOf(EvIo::class, $ioWatcher);

        $ioWatcher->stop();

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
