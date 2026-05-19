<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\InvalidConfigException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerConfigTest extends TestCase
{
    #[Test]
    public function default_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig();

        $this->assertSame(10, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function custom_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 25);

        $this->assertSame(25, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function rejects_zero_max_accepts_per_cycle(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max accepts per cycle must be positive');

        new ServerConfig(maxAcceptsPerCycle: 0);
    }

    #[Test]
    public function rejects_negative_max_accepts_per_cycle(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max accepts per cycle must be positive');

        new ServerConfig(maxAcceptsPerCycle: -1);
    }

    #[Test]
    public function accepts_one_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 1);

        $this->assertSame(1, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function accepts_large_max_accepts_per_cycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 1000);

        $this->assertSame(1000, $config->maxAcceptsPerCycle);
    }

    #[Test]
    public function default_socket_backlog(): void
    {
        $config = new ServerConfig();

        $this->assertSame(511, $config->socketBacklog);
    }

    #[Test]
    public function custom_socket_backlog(): void
    {
        $config = new ServerConfig(socketBacklog: 1024);

        $this->assertSame(1024, $config->socketBacklog);
    }

    #[Test]
    public function rejects_zero_socket_backlog(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Socket backlog must be positive');

        new ServerConfig(socketBacklog: 0);
    }

    #[Test]
    public function rejects_negative_socket_backlog(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Socket backlog must be positive');

        new ServerConfig(socketBacklog: -1);
    }

    #[Test]
    public function accepts_one_socket_backlog(): void
    {
        $config = new ServerConfig(socketBacklog: 1);

        $this->assertSame(1, $config->socketBacklog);
    }

    #[Test]
    public function default_header_cache_limit(): void
    {
        $config = new ServerConfig();

        $this->assertSame(100, $config->headerCacheLimit);
    }

    #[Test]
    public function custom_header_cache_limit(): void
    {
        $config = new ServerConfig(headerCacheLimit: 500);

        $this->assertSame(500, $config->headerCacheLimit);
    }

    #[Test]
    public function rejects_zero_header_cache_limit(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Header cache limit must be positive');

        new ServerConfig(headerCacheLimit: 0);
    }

    #[Test]
    public function rejects_negative_header_cache_limit(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Header cache limit must be positive');

        new ServerConfig(headerCacheLimit: -1);
    }

    #[Test]
    public function accepts_one_header_cache_limit(): void
    {
        $config = new ServerConfig(headerCacheLimit: 1);

        $this->assertSame(1, $config->headerCacheLimit);
    }

    #[Test]
    public function default_enable_security_headers(): void
    {
        $config = new ServerConfig();

        $this->assertTrue($config->enableSecurityHeaders);
    }

    #[Test]
    public function disable_security_headers(): void
    {
        $config = new ServerConfig(enableSecurityHeaders: false);

        $this->assertFalse($config->enableSecurityHeaders);
    }

    #[Test]
    public function default_frame_options(): void
    {
        $config = new ServerConfig();

        $this->assertSame('DENY', $config->frameOptions);
    }

    #[Test]
    public function custom_frame_options(): void
    {
        $config = new ServerConfig(frameOptions: 'SAMEORIGIN');

        $this->assertSame('SAMEORIGIN', $config->frameOptions);
    }

    #[Test]
    public function rejects_invalid_frame_options(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Frame options must be one of');

        new ServerConfig(frameOptions: 'INVALID');
    }

    #[Test]
    public function rejects_invalid_referrer_policy(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Referrer policy must be one of');

        new ServerConfig(referrerPolicy: 'invalid-policy');
    }

    #[Test]
    public function accepts_all_valid_referrer_policies(): void
    {
        $validPolicies = [
            'no-referrer',
            'no-referrer-when-downgrade',
            'origin',
            'origin-when-cross-origin',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
            'unsafe-url',
        ];

        foreach ($validPolicies as $policy) {
            $config = new ServerConfig(referrerPolicy: $policy);
            $this->assertSame($policy, $config->referrerPolicy);
        }
    }

    #[Test]
    public function default_referrer_policy(): void
    {
        $config = new ServerConfig();

        $this->assertSame('strict-origin-when-cross-origin', $config->referrerPolicy);
    }

    #[Test]
    public function custom_referrer_policy(): void
    {
        $config = new ServerConfig(referrerPolicy: 'no-referrer');

        $this->assertSame('no-referrer', $config->referrerPolicy);
    }

    #[Test]
    public function default_permissions_policy(): void
    {
        $config = new ServerConfig();

        $this->assertSame('geolocation=(), microphone=(), camera=()', $config->permissionsPolicy);
    }

    #[Test]
    public function custom_permissions_policy(): void
    {
        $config = new ServerConfig(permissionsPolicy: 'fullscreen=*');

        $this->assertSame('fullscreen=*', $config->permissionsPolicy);
    }

    #[Test]
    public function host_validation_rejects_empty_string(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Host cannot be empty');

        new ServerConfig(host: '');
    }

    #[Test]
    public function host_validation_rejects_invalid_host(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Invalid host');

        new ServerConfig(host: 'not!valid!host');
    }

    #[Test]
    public function host_validation_accepts_valid_ip(): void
    {
        $config = new ServerConfig(host: '192.168.1.1');

        $this->assertSame('192.168.1.1', $config->host);
    }

    #[Test]
    public function host_validation_accepts_localhost(): void
    {
        $config = new ServerConfig(host: 'localhost');

        $this->assertSame('localhost', $config->host);
    }

    #[Test]
    public function host_validation_accepts_wildcard_ip(): void
    {
        $config = new ServerConfig(host: '0.0.0.0');

        $this->assertSame('0.0.0.0', $config->host);
    }

    #[Test]
    public function cors_enabled_without_origins_throws(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('CORS enabled but no allowed origins specified');

        new ServerConfig(enableCors: true);
    }

    #[Test]
    public function cors_enabled_with_origins_is_valid(): void
    {
        $config = new ServerConfig(
            enableCors: true,
            corsAllowedOrigins: ['https://example.com'],
        );

        $this->assertTrue($config->enableCors);
        $this->assertSame(['https://example.com'], $config->corsAllowedOrigins);
    }

    #[Test]
    public function cors_disabled_by_default(): void
    {
        $config = new ServerConfig();

        $this->assertFalse($config->enableCors);
    }

    #[Test]
    public function cors_default_allowed_methods(): void
    {
        $config = new ServerConfig();

        $this->assertSame(
            ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            $config->corsAllowedMethods,
        );
    }

    #[Test]
    public function cors_default_allowed_headers(): void
    {
        $config = new ServerConfig();

        $this->assertSame(
            ['Content-Type', 'Authorization'],
            $config->corsAllowedHeaders,
        );
    }

    #[Test]
    public function cors_default_max_age(): void
    {
        $config = new ServerConfig();

        $this->assertSame(86400, $config->corsMaxAge);
    }

    #[Test]
    public function cors_default_allow_credentials(): void
    {
        $config = new ServerConfig();

        $this->assertFalse($config->corsAllowCredentials);
    }

    #[Test]
    public function cors_default_expose_headers(): void
    {
        $config = new ServerConfig();

        $this->assertSame([], $config->corsExposeHeaders);
    }

    #[Test]
    public function cors_custom_configuration(): void
    {
        $config = new ServerConfig(
            enableCors: true,
            corsAllowedOrigins: ['https://example.com'],
            corsAllowedMethods: ['GET', 'POST'],
            corsAllowedHeaders: ['Content-Type'],
            corsAllowCredentials: true,
            corsMaxAge: 3600,
            corsExposeHeaders: ['X-Custom'],
        );

        $this->assertTrue($config->enableCors);
        $this->assertSame(['https://example.com'], $config->corsAllowedOrigins);
        $this->assertSame(['GET', 'POST'], $config->corsAllowedMethods);
        $this->assertSame(['Content-Type'], $config->corsAllowedHeaders);
        $this->assertTrue($config->corsAllowCredentials);
        $this->assertSame(3600, $config->corsMaxAge);
        $this->assertSame(['X-Custom'], $config->corsExposeHeaders);
    }

    #[Test]
    public function cors_wildcard_with_credentials_throws_exception(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('CORS credentials are not allowed with wildcard origin');

        new ServerConfig(
            enableCors: true,
            corsAllowedOrigins: ['*'],
            corsAllowCredentials: true,
        );
    }

    #[Test]
    public function hsts_negative_max_age_throws_exception(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('HSTS max-age must be non-negative');

        new ServerConfig(
            enableHsts: true,
            hstsMaxAge: -1,
        );
    }

    #[Test]
    public function hsts_disabled_with_negative_max_age_does_not_throw(): void
    {
        $config = new ServerConfig(
            enableHsts: false,
            hstsMaxAge: -1,
        );

        $this->assertSame(-1, $config->hstsMaxAge);
    }
}
