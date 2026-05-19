<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class HttpsTest extends TestCase
{
    private ?Server $server = null;
    private string $certFile;
    private string $keyFile;

    #[Override]
    protected function setUp(): void
    {
        if (false === extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $tmpDir = sys_get_temp_dir();
        $this->certFile = $tmpDir . '/test_cert_' . uniqid() . '.pem';
        $this->keyFile = $tmpDir . '/test_key_' . uniqid() . '.pem';

        $this->generateSelfSignedCert();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            try {
                $this->server->stop();
                $this->server->reset();
            } catch (Throwable) {
            }
            $this->server = null;
        }

        if (file_exists($this->certFile)) {
            unlink($this->certFile);
        }

        if (file_exists($this->keyFile)) {
            unlink($this->keyFile);
        }

        parent::tearDown();
    }

    private function startSslServer(int $port): Server
    {
        $server = new Server(new ServerConfig(
            host: '127.0.0.1',
            port: $port,
            ssl: true,
            sslCert: $this->certFile,
            sslKey: $this->keyFile,
            requestTimeout: 5,
            connectionTimeout: 5,
        ));

        $this->assertTrue($server->start(), 'SSL server should start successfully');
        $this->server = $server;

        return $server;
    }

    #[Test]
    public function ssl_server_starts_successfully(): void
    {
        $port = $this->findAvailablePort();
        $server = $this->startSslServer($port);

        $this->assertNotNull($server->getSocketResource());
    }

    #[Test]
    public function ssl_server_returns_stream_resource(): void
    {
        $port = $this->findAvailablePort();
        $server = $this->startSslServer($port);

        $resource = $server->getSocketResource();
        $this->assertNotNull($resource);
        $this->assertIsResource($resource);
    }

    #[Test]
    public function ssl_server_can_be_stopped_and_restarted(): void
    {
        $port = $this->findAvailablePort();
        $server = $this->startSslServer($port);

        $server->stop();
        $server->reset();

        $newPort = $this->findAvailablePort();
        $server2 = new Server(new ServerConfig(
            host: '127.0.0.1',
            port: $newPort,
            ssl: true,
            sslCert: $this->certFile,
            sslKey: $this->keyFile,
        ));

        $this->assertTrue($server2->start());
        $server2->stop();
        $server2->reset();
    }

    #[Test]
    public function ssl_server_accepts_plain_tcp_connection(): void
    {
        $port = $this->findAvailablePort();
        $server = $this->startSslServer($port);

        $previousErrorReporting = error_reporting(0);
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        error_reporting($previousErrorReporting);

        $this->assertNotFalse($client, "Should be able to connect to SSL server via TCP: $errstr ($errno)");

        fwrite($client, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");

        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50000);
            if ($server->hasRequest()) {
                break;
            }
        }

        fclose($client);
    }

    #[Test]
    public function ssl_server_metrics_are_available(): void
    {
        $port = $this->findAvailablePort();
        $server = $this->startSslServer($port);

        $metrics = $server->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('memory_usage', $metrics);
        $this->assertArrayHasKey('memory_peak', $metrics);
    }

    private function generateSelfSignedCert(): void
    {
        $dn = [
            'countryName' => 'US',
            'stateOrProvinceName' => 'Test',
            'localityName' => 'TestCity',
            'organizationName' => 'TestOrg',
            'commonName' => 'localhost',
        ];

        $privkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new($dn, $privkey);
        $x509 = openssl_csr_sign($csr, null, $privkey, 365);

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($privkey, $keyOut);

        file_put_contents($this->certFile, $certOut);
        file_put_contents($this->keyFile, $keyOut);
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }
}
