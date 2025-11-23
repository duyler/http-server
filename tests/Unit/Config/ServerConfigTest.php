<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ServerConfigTest extends TestCase
{
    #[Test]
    public function creates_with_default_values(): void
    {
        $config = new ServerConfig();

        $this->assertSame('0.0.0.0', $config->host);
        $this->assertSame(8080, $config->port);
        $this->assertFalse($config->ssl);
        $this->assertNull($config->sslCert);
        $this->assertNull($config->sslKey);
        $this->assertNull($config->publicPath);
        $this->assertSame(30, $config->requestTimeout);
        $this->assertSame(60, $config->connectionTimeout);
        $this->assertSame(1000, $config->maxConnections);
        $this->assertSame(10485760, $config->maxRequestSize);
        $this->assertSame(8192, $config->bufferSize);
        $this->assertTrue($config->enableKeepAlive);
        $this->assertSame(30, $config->keepAliveTimeout);
        $this->assertSame(100, $config->keepAliveMaxRequests);
        $this->assertTrue($config->enableStaticCache);
        $this->assertSame(52428800, $config->staticCacheSize);
    }

    #[Test]
    public function creates_with_custom_values(): void
    {
        $config = new ServerConfig(
            host: '127.0.0.1',
            port: 9090,
            requestTimeout: 60,
        );

        $this->assertSame('127.0.0.1', $config->host);
        $this->assertSame(9090, $config->port);
        $this->assertSame(60, $config->requestTimeout);
    }

    #[Test]
    public function throws_exception_on_invalid_port(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between 1 and 65535');

        new ServerConfig(port: 0);
    }

    #[Test]
    public function throws_exception_on_port_too_high(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between 1 and 65535');

        new ServerConfig(port: 70000);
    }

    #[Test]
    public function throws_exception_on_negative_request_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Request timeout must be positive');

        new ServerConfig(requestTimeout: 0);
    }

    #[Test]
    public function throws_exception_on_negative_connection_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection timeout must be positive');

        new ServerConfig(connectionTimeout: 0);
    }

    #[Test]
    public function throws_exception_on_negative_max_connections(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max connections must be positive');

        new ServerConfig(maxConnections: 0);
    }

    #[Test]
    public function throws_exception_on_too_small_max_request_size(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max request size must be at least 1024 bytes');

        new ServerConfig(maxRequestSize: 512);
    }

    #[Test]
    public function throws_exception_on_too_small_buffer_size(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Buffer size must be at least 1024 bytes');

        new ServerConfig(bufferSize: 512);
    }

    #[Test]
    public function throws_exception_when_ssl_enabled_without_cert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate path is required when SSL is enabled');

        new ServerConfig(ssl: true);
    }

    #[Test]
    public function throws_exception_when_ssl_enabled_without_key(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL key path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '/path/to/cert.pem');
    }

    #[Test]
    public function is_readonly(): void
    {
        $config = new ServerConfig();

        $reflection = new ReflectionClass($config);
        $this->assertTrue($reflection->isReadOnly());
    }
}
