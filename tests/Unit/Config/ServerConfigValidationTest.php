<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

class ServerConfigValidationTest extends TestCase
{
    public function testValidatesPort(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between');

        new ServerConfig(port: 0);
    }

    public function testRejectsPortBelowRange(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between');

        new ServerConfig(port: -1);
    }

    public function testRejectsPortAboveRange(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Port must be between');

        new ServerConfig(port: 65536);
    }

    public function testAcceptsMinimumPort(): void
    {
        $config = new ServerConfig(port: 1);

        $this->assertSame(1, $config->port);
    }

    public function testAcceptsMaximumPort(): void
    {
        $config = new ServerConfig(port: 65535);

        $this->assertSame(65535, $config->port);
    }

    public function testRejectsZeroRequestTimeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Request timeout must be positive');

        new ServerConfig(requestTimeout: 0);
    }

    public function testRejectsNegativeRequestTimeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Request timeout must be positive');

        new ServerConfig(requestTimeout: -1);
    }

    public function testAcceptsMinimumRequestTimeout(): void
    {
        $config = new ServerConfig(requestTimeout: 1);

        $this->assertSame(1, $config->requestTimeout);
    }

    public function testRejectsZeroConnectionTimeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection timeout must be positive');

        new ServerConfig(connectionTimeout: 0);
    }

    public function testRejectsNegativeConnectionTimeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Connection timeout must be positive');

        new ServerConfig(connectionTimeout: -1);
    }

    public function testRejectsZeroMaxConnections(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max connections must be positive');

        new ServerConfig(maxConnections: 0);
    }

    public function testRejectsNegativeMaxConnections(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max connections must be positive');

        new ServerConfig(maxConnections: -1);
    }

    public function testRejectsTooSmallMaxRequestSize(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max request size must be at least 1024 bytes');

        new ServerConfig(maxRequestSize: 1023);
    }

    public function testAcceptsMinimumMaxRequestSize(): void
    {
        $config = new ServerConfig(maxRequestSize: 1024);

        $this->assertSame(1024, $config->maxRequestSize);
    }

    public function testRejectsTooSmallBufferSize(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Buffer size must be at least 1024 bytes');

        new ServerConfig(bufferSize: 1023);
    }

    public function testAcceptsMinimumBufferSize(): void
    {
        $config = new ServerConfig(bufferSize: 1024);

        $this->assertSame(1024, $config->bufferSize);
    }

    public function testRejectsSslWithoutCert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslKey: '/path/to/key.pem');
    }

    public function testRejectsSslWithEmptyCert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '', sslKey: '/path/to/key.pem');
    }

    public function testRejectsSslWithoutKey(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL key path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '/path/to/cert.pem');
    }

    public function testRejectsSslWithEmptyKey(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL key path is required when SSL is enabled');

        new ServerConfig(ssl: true, sslCert: '/path/to/cert.pem', sslKey: '');
    }

    public function testRejectsSslWithNonexistentCert(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('SSL certificate file not found');

        new ServerConfig(
            ssl: true,
            sslCert: '/nonexistent/cert.pem',
            sslKey: '/nonexistent/key.pem',
        );
    }

    public function testRejectsSslWithNonexistentKey(): void
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

    public function testRejectsNonexistentPublicPath(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Public path is not a directory');

        new ServerConfig(publicPath: '/nonexistent/path');
    }

    public function testRejectsPublicPathAsFile(): void
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

    public function testAcceptsValidPublicPath(): void
    {
        $tempDir = sys_get_temp_dir();

        $config = new ServerConfig(publicPath: $tempDir);

        $this->assertSame($tempDir, $config->publicPath);
    }

    public function testRejectsZeroKeepAliveTimeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive timeout must be positive');

        new ServerConfig(keepAliveTimeout: 0);
    }

    public function testRejectsNegativeKeepAliveTimeout(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive timeout must be positive');

        new ServerConfig(keepAliveTimeout: -1);
    }

    public function testRejectsZeroKeepAliveMaxRequests(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive max requests must be positive');

        new ServerConfig(keepAliveMaxRequests: 0);
    }

    public function testRejectsNegativeKeepAliveMaxRequests(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Keep-alive max requests must be positive');

        new ServerConfig(keepAliveMaxRequests: -1);
    }

    public function testRejectsNegativeStaticCacheSize(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Static cache size must be non-negative');

        new ServerConfig(staticCacheSize: -1);
    }

    public function testAcceptsZeroStaticCacheSize(): void
    {
        $config = new ServerConfig(staticCacheSize: 0);

        $this->assertSame(0, $config->staticCacheSize);
    }

    public function testRejectsZeroRateLimitRequests(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Rate limit requests must be positive');

        new ServerConfig(rateLimitRequests: 0);
    }

    public function testRejectsZeroRateLimitWindow(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Rate limit window must be positive');

        new ServerConfig(rateLimitWindow: 0);
    }

    public function testAcceptsAllDefaultValues(): void
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
        $this->assertFalse($config->debugMode);
        $this->assertSame(134217728, $config->memoryLimit);
    }

    public function testAcceptsCustomValues(): void
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

    public function testDefaultMemoryLimit(): void
    {
        $config = new ServerConfig();

        $this->assertSame(134217728, $config->memoryLimit);
    }

    public function testCustomMemoryLimit(): void
    {
        $config = new ServerConfig(memoryLimit: 268435456);

        $this->assertSame(268435456, $config->memoryLimit);
    }

    public function testRejectsMemoryLimitBelowMinimum(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Memory limit must be at least 1MB');

        new ServerConfig(memoryLimit: 1048575);
    }

    public function testAcceptsMinimumMemoryLimit(): void
    {
        $config = new ServerConfig(memoryLimit: 1048576);

        $this->assertSame(1048576, $config->memoryLimit);
    }
}
