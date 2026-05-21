<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerConfigValidationTest extends TestCase
{
    #[Test]
    public function validates_port(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between');

        new ServerConfig(port: 0);
    }

    #[Test]
    public function rejects_port_below_range(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between');

        new ServerConfig(port: -1);
    }

    #[Test]
    public function rejects_port_above_range(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between');

        new ServerConfig(port: 65536);
    }

    #[Test]
    public function accepts_minimum_port(): void
    {
        $config = new ServerConfig(port: 1);

        $this->assertSame(1, $config->port);
    }

    #[Test]
    public function accepts_maximum_port(): void
    {
        $config = new ServerConfig(port: 65535);

        $this->assertSame(65535, $config->port);
    }

    #[Test]
    public function rejects_zero_request_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Request timeout must be positive');

        new ServerConfig(requestTimeout: 0);
    }

    #[Test]
    public function rejects_negative_request_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Request timeout must be positive');

        new ServerConfig(requestTimeout: -1);
    }

    #[Test]
    public function accepts_minimum_request_timeout(): void
    {
        $config = new ServerConfig(requestTimeout: 1);

        $this->assertSame(1, $config->requestTimeout);
    }

    #[Test]
    public function rejects_zero_connection_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection timeout must be positive');

        new ServerConfig(connectionTimeout: 0);
    }

    #[Test]
    public function rejects_negative_connection_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection timeout must be positive');

        new ServerConfig(connectionTimeout: -1);
    }

    #[Test]
    public function rejects_zero_max_connections(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max connections must be positive');

        new ServerConfig(maxConnections: 0);
    }

    #[Test]
    public function rejects_negative_max_connections(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max connections must be positive');

        new ServerConfig(maxConnections: -1);
    }

    #[Test]
    public function rejects_too_small_max_request_size(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max request size must be at least 1024 bytes');

        new ServerConfig(maxRequestSize: 1023);
    }

    #[Test]
    public function accepts_minimum_max_request_size(): void
    {
        $config = new ServerConfig(maxRequestSize: 1024);

        $this->assertSame(1024, $config->maxRequestSize);
    }

    #[Test]
    public function rejects_too_small_buffer_size(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Buffer size must be at least 1024 bytes');

        new ServerConfig(bufferSize: 1023);
    }

    #[Test]
    public function accepts_minimum_buffer_size(): void
    {
        $config = new ServerConfig(bufferSize: 1024);

        $this->assertSame(1024, $config->bufferSize);
    }

    #[Test]
    public function rejects_ssl_without_cert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslKey: '/path/to/key.pem');
    }

    #[Test]
    public function rejects_ssl_with_empty_cert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '', sslKey: '/path/to/key.pem');
    }

    #[Test]
    public function rejects_ssl_without_key(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL key path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '/path/to/cert.pem');
    }

    #[Test]
    public function rejects_ssl_with_empty_key(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL key path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '/path/to/cert.pem', sslKey: '');
    }

    #[Test]
    public function rejects_ssl_with_nonexistent_cert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate file not found');

        new ServerConfig(
            ssl: true,
            sslCert: '/nonexistent/cert.pem',
            sslKey: '/nonexistent/key.pem',
        );
    }

    #[Test]
    public function rejects_ssl_with_nonexistent_key(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert');
        file_put_contents($certFile, '');

        try {
            $this->expectException(InvalidConfigException::class);
            $this->expectExceptionMessage('SSL key file not found');

            new ServerConfig(
                ssl: true,
                sslCert: $certFile,
                sslKey: '/nonexistent/key.pem',
            );
        } finally {
            unlink($certFile);
        }
    }

    #[Test]
    public function rejects_nonexistent_public_path(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Public path is not a directory');

        new ServerConfig(publicPath: '/nonexistent/path');
    }

    #[Test]
    public function rejects_public_path_as_file(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test');

        try {
            $this->expectException(InvalidConfigException::class);
            $this->expectExceptionMessage('Public path is not a directory');

            new ServerConfig(publicPath: $tempFile);
        } finally {
            unlink($tempFile);
        }
    }

    #[Test]
    public function accepts_valid_public_path(): void
    {
        $tempDir = sys_get_temp_dir();

        $config = new ServerConfig(publicPath: $tempDir);

        $this->assertSame($tempDir, $config->publicPath);
    }

    #[Test]
    public function rejects_zero_keep_alive_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive timeout must be positive');

        new ServerConfig(keepAliveTimeout: 0);
    }

    #[Test]
    public function rejects_negative_keep_alive_timeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive timeout must be positive');

        new ServerConfig(keepAliveTimeout: -1);
    }

    #[Test]
    public function rejects_zero_keep_alive_max_requests(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive max requests must be positive');

        new ServerConfig(keepAliveMaxRequests: 0);
    }

    #[Test]
    public function rejects_negative_keep_alive_max_requests(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive max requests must be positive');

        new ServerConfig(keepAliveMaxRequests: -1);
    }

    #[Test]
    public function rejects_negative_static_cache_size(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Static cache size must be non-negative');

        new ServerConfig(staticCacheSize: -1);
    }

    #[Test]
    public function accepts_zero_static_cache_size(): void
    {
        $config = new ServerConfig(staticCacheSize: 0);

        $this->assertSame(0, $config->staticCacheSize);
    }

    #[Test]
    public function rejects_zero_rate_limit_requests(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Rate limit requests must be positive');

        new ServerConfig(rateLimitRequests: 0);
    }

    #[Test]
    public function rejects_zero_rate_limit_window(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Rate limit window must be positive');

        new ServerConfig(rateLimitWindow: 0);
    }

    #[Test]
    public function accepts_all_default_values(): void
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
        $this->assertFalse($config->enableRateLimit);
        $this->assertSame(100, $config->rateLimitRequests);
        $this->assertSame(60, $config->rateLimitWindow);
        $this->assertSame(10, $config->maxAcceptsPerCycle);
        $this->assertSame(511, $config->socketBacklog);
        $this->assertSame(100, $config->headerCacheLimit);
        $this->assertFalse($config->enableCors);
        $this->assertSame([], $config->corsAllowedOrigins);
        $this->assertSame(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'], $config->corsAllowedMethods);
        $this->assertSame(['Content-Type', 'Authorization'], $config->corsAllowedHeaders);
        $this->assertFalse($config->corsAllowCredentials);
        $this->assertSame(86400, $config->corsMaxAge);
        $this->assertSame([], $config->corsExposeHeaders);
        $this->assertFalse($config->debugMode);
        $this->assertSame(134217728, $config->memoryLimit);
    }

    #[Test]
    public function accepts_custom_values(): void
    {
        $config = new ServerConfig(
            host: '192.168.1.1',
            port: 9000,
            requestTimeout: 60,
            connectionTimeout: 120,
            maxConnections: 500,
            maxRequestSize: 20971520,
            bufferSize: 16384,
            enableKeepAlive: false,
            keepAliveTimeout: 15,
            keepAliveMaxRequests: 50,
            enableStaticCache: false,
            staticCacheSize: 1048576,
            enableRateLimit: true,
            rateLimitRequests: 200,
            rateLimitWindow: 120,
            maxAcceptsPerCycle: 20,
            socketBacklog: 1024,
            headerCacheLimit: 200,
            debugMode: true,
        );

        $this->assertSame('192.168.1.1', $config->host);
        $this->assertSame(9000, $config->port);
        $this->assertSame(60, $config->requestTimeout);
        $this->assertSame(120, $config->connectionTimeout);
        $this->assertSame(500, $config->maxConnections);
        $this->assertSame(20971520, $config->maxRequestSize);
        $this->assertSame(16384, $config->bufferSize);
        $this->assertFalse($config->enableKeepAlive);
        $this->assertSame(15, $config->keepAliveTimeout);
        $this->assertSame(50, $config->keepAliveMaxRequests);
        $this->assertFalse($config->enableStaticCache);
        $this->assertSame(1048576, $config->staticCacheSize);
        $this->assertTrue($config->enableRateLimit);
        $this->assertSame(200, $config->rateLimitRequests);
        $this->assertSame(120, $config->rateLimitWindow);
        $this->assertSame(20, $config->maxAcceptsPerCycle);
        $this->assertSame(1024, $config->socketBacklog);
        $this->assertSame(200, $config->headerCacheLimit);
        $this->assertTrue($config->debugMode);
    }

    #[Test]
    public function default_memory_limit(): void
    {
        $config = new ServerConfig();

        $this->assertSame(134217728, $config->memoryLimit);
    }

    #[Test]
    public function custom_memory_limit(): void
    {
        $config = new ServerConfig(memoryLimit: 268435456);

        $this->assertSame(268435456, $config->memoryLimit);
    }

    #[Test]
    public function rejects_memory_limit_below_minimum(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Memory limit must be at least 1MB');

        new ServerConfig(memoryLimit: 1048575);
    }

    #[Test]
    public function accepts_minimum_memory_limit(): void
    {
        $config = new ServerConfig(memoryLimit: 1048576);

        $this->assertSame(1048576, $config->memoryLimit);
    }
}
