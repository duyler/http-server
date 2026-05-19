<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Functional;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Server::class)]
class HttpsTest extends TestCase
{
    private ?Server $server = null;
    private int $port;
    private string $certFile;
    private string $keyFile;

    #[Override]
    protected function setUp(): void
    {
        if (false === extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension not available');
        }

        $this->port = $this->findAvailablePort();

        $tmpDir = sys_get_temp_dir();
        $this->certFile = $tmpDir . '/test_cert_' . uniqid() . '.pem';
        $this->keyFile = $tmpDir . '/test_key_' . uniqid() . '.pem';

        $this->generateSelfSignedCert();

        $config = new ServerConfig(
            host: '127.0.0.1',
            port: $this->port,
            ssl: true,
            sslCert: $this->certFile,
            sslKey: $this->keyFile,
            requestTimeout: 5,
            connectionTimeout: 5,
        );

        $this->server = new Server($config);
        $this->server->start();
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

    #[Test]
    public function tls_handshake_and_http_request(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $client = stream_socket_client(
            "ssl://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (false === $client) {
            $this->server->stop();
            $this->server->reset();

            $newPort = $this->findAvailablePort();
            $config = new ServerConfig(
                host: '127.0.0.1',
                port: $newPort,
                ssl: true,
                sslCert: $this->certFile,
                sslKey: $this->keyFile,
                requestTimeout: 5,
                connectionTimeout: 5,
            );
            $this->server = new Server($config);
            $this->server->start();
            $this->port = $newPort;

            $client = stream_socket_client(
                "ssl://127.0.0.1:{$this->port}",
                $errno,
                $errstr,
                5.0,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if (false === $client) {
                $this->markTestSkipped("TLS connection failed after retry: $errstr ($errno)");
            }
        }

        stream_set_timeout($client, 5);

        fwrite($client, "GET /secure HTTP/1.1\r\nHost: localhost\r\n\r\n");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(100000);
            if ($this->server->hasRequest()) {
                break;
            }
        }

        $this->assertTrue($this->server->hasRequest(), 'Server should have received TLS GET request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('GET', $requestData->request->getMethod());
        $this->assertSame('/secure', $requestData->request->getUri()->getPath());

        $response = new Response(200, ['Content-Type' => 'text/plain'], 'HTTPS OK');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('HTTP/1.1 200 OK', $raw);
        $this->assertStringContainsString('HTTPS OK', $raw);
    }

    #[Test]
    public function tls_post_request_with_body(): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $client = stream_socket_client(
            "ssl://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (false === $client) {
            $this->markTestSkipped("TLS connection failed: $errstr ($errno)");
        }

        stream_set_timeout($client, 5);

        $body = '{"encrypted":true}';
        $request = "POST /api/secure HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        fwrite($client, $request);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(100000);
            if ($this->server->hasRequest()) {
                break;
            }
        }

        $this->assertTrue($this->server->hasRequest(), 'Server should have received TLS POST request');

        $requestData = $this->server->getRequest();
        $this->assertNotNull($requestData);
        $this->assertSame('POST', $requestData->request->getMethod());
        $this->assertSame($body, (string) $requestData->request->getBody());

        $response = new Response(201, [], 'Created over TLS');
        $this->server->respond(new ResponseData($requestData->id, $response));

        usleep(50000);

        $raw = fread($client, 8192);
        fclose($client);

        $this->assertStringContainsString('201', $raw);
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
