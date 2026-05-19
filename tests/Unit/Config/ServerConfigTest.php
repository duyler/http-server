<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

class ServerConfigTest extends TestCase
{
    public function testDefaultMaxAcceptsPerCycle(): void
    {
        $config = new ServerConfig();

        $this->assertSame(10, $config->maxAcceptsPerCycle);
    }

    public function testCustomMaxAcceptsPerCycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 25);

        $this->assertSame(25, $config->maxAcceptsPerCycle);
    }

    public function testRejectsZeroMaxAcceptsPerCycle(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max accepts per cycle must be positive');

        new ServerConfig(maxAcceptsPerCycle: 0);
    }

    public function testRejectsNegativeMaxAcceptsPerCycle(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Max accepts per cycle must be positive');

        new ServerConfig(maxAcceptsPerCycle: -1);
    }

    public function testAcceptsOneMaxAcceptsPerCycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 1);

        $this->assertSame(1, $config->maxAcceptsPerCycle);
    }

    public function testAcceptsLargeMaxAcceptsPerCycle(): void
    {
        $config = new ServerConfig(maxAcceptsPerCycle: 1000);

        $this->assertSame(1000, $config->maxAcceptsPerCycle);
    }

    public function testDefaultSocketBacklog(): void
    {
        $config = new ServerConfig();

        $this->assertSame(511, $config->socketBacklog);
    }

    public function testCustomSocketBacklog(): void
    {
        $config = new ServerConfig(socketBacklog: 1024);

        $this->assertSame(1024, $config->socketBacklog);
    }

    public function testRejectsZeroSocketBacklog(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Socket backlog must be positive');

        new ServerConfig(socketBacklog: 0);
    }

    public function testRejectsNegativeSocketBacklog(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Socket backlog must be positive');

        new ServerConfig(socketBacklog: -1);
    }

    public function testAcceptsOneSocketBacklog(): void
    {
        $config = new ServerConfig(socketBacklog: 1);

        $this->assertSame(1, $config->socketBacklog);
    }

    public function testDefaultHeaderCacheLimit(): void
    {
        $config = new ServerConfig();

        $this->assertSame(100, $config->headerCacheLimit);
    }

    public function testCustomHeaderCacheLimit(): void
    {
        $config = new ServerConfig(headerCacheLimit: 500);

        $this->assertSame(500, $config->headerCacheLimit);
    }

    public function testRejectsZeroHeaderCacheLimit(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Header cache limit must be positive');

        new ServerConfig(headerCacheLimit: 0);
    }

    public function testRejectsNegativeHeaderCacheLimit(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Header cache limit must be positive');

        new ServerConfig(headerCacheLimit: -1);
    }

    public function testAcceptsOneHeaderCacheLimit(): void
    {
        $config = new ServerConfig(headerCacheLimit: 1);

        $this->assertSame(1, $config->headerCacheLimit);
    }

    public function testDefaultEnableSecurityHeaders(): void
    {
        $config = new ServerConfig();

        $this->assertTrue($config->enableSecurityHeaders);
    }

    public function testDisableSecurityHeaders(): void
    {
        $config = new ServerConfig(enableSecurityHeaders: false);

        $this->assertFalse($config->enableSecurityHeaders);
    }

    public function testDefaultFrameOptions(): void
    {
        $config = new ServerConfig();

        $this->assertSame('DENY', $config->frameOptions);
    }

    public function testCustomFrameOptions(): void
    {
        $config = new ServerConfig(frameOptions: 'SAMEORIGIN');

        $this->assertSame('SAMEORIGIN', $config->frameOptions);
    }

    public function testRejectsInvalidFrameOptions(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Frame options must be one of');

        new ServerConfig(frameOptions: 'INVALID');
    }

    public function testRejectsInvalidReferrerPolicy(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Referrer policy must be one of');

        new ServerConfig(referrerPolicy: 'invalid-policy');
    }

    public function testAcceptsAllValidReferrerPolicies(): void
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

    public function testDefaultReferrerPolicy(): void
    {
        $config = new ServerConfig();

        $this->assertSame('strict-origin-when-cross-origin', $config->referrerPolicy);
    }

    public function testCustomReferrerPolicy(): void
    {
        $config = new ServerConfig(referrerPolicy: 'no-referrer');

        $this->assertSame('no-referrer', $config->referrerPolicy);
    }

    public function testDefaultPermissionsPolicy(): void
    {
        $config = new ServerConfig();

        $this->assertSame('geolocation=(), microphone=(), camera=()', $config->permissionsPolicy);
    }

    public function testCustomPermissionsPolicy(): void
    {
        $config = new ServerConfig(permissionsPolicy: 'fullscreen=*');

        $this->assertSame('fullscreen=*', $config->permissionsPolicy);
    }

    public function testHostValidationRejectsEmptyString(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Host cannot be empty');

        new ServerConfig(host: '');
    }

    public function testHostValidationRejectsInvalidHost(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Invalid host');

        new ServerConfig(host: 'not!valid!host');
    }

    public function testHostValidationAcceptsValidIp(): void
    {
        $config = new ServerConfig(host: '192.168.1.1');

        $this->assertSame('192.168.1.1', $config->host);
    }

    public function testHostValidationAcceptsLocalhost(): void
    {
        $config = new ServerConfig(host: 'localhost');

        $this->assertSame('localhost', $config->host);
    }

    public function testHostValidationAcceptsWildcardIp(): void
    {
        $config = new ServerConfig(host: '0.0.0.0');

        $this->assertSame('0.0.0.0', $config->host);
    }

    public function testCorsEnabledWithoutOriginsThrows(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('CORS enabled but no allowed origins specified');

        new ServerConfig(enableCors: true);
    }

    public function testCorsEnabledWithOriginsIsValid(): void
    {
        $config = new ServerConfig(
            enableCors: true,
            corsAllowedOrigins: ['https://example.com'],
        );

        $this->assertTrue($config->enableCors);
        $this->assertSame(['https://example.com'], $config->corsAllowedOrigins);
    }

    public function testCorsDisabledByDefault(): void
    {
        $config = new ServerConfig();

        $this->assertFalse($config->enableCors);
    }

    public function testCorsDefaultAllowedMethods(): void
    {
        $config = new ServerConfig();

        $this->assertSame(
            ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            $config->corsAllowedMethods,
        );
    }

    public function testCorsDefaultAllowedHeaders(): void
    {
        $config = new ServerConfig();

        $this->assertSame(
            ['Content-Type', 'Authorization'],
            $config->corsAllowedHeaders,
        );
    }

    public function testCorsDefaultMaxAge(): void
    {
        $config = new ServerConfig();

        $this->assertSame(86400, $config->corsMaxAge);
    }

    public function testCorsDefaultAllowCredentials(): void
    {
        $config = new ServerConfig();

        $this->assertFalse($config->corsAllowCredentials);
    }

    public function testCorsDefaultExposeHeaders(): void
    {
        $config = new ServerConfig();

        $this->assertSame([], $config->corsExposeHeaders);
    }

    public function testCorsCustomConfiguration(): void
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

    public function testCorsWildcardWithCredentialsThrowsException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('CORS credentials are not allowed with wildcard origin');

        new ServerConfig(
            enableCors: true,
            corsAllowedOrigins: ['*'],
            corsAllowCredentials: true,
        );
    }

    public function testHstsNegativeMaxAgeThrowsException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('HSTS max-age must be non-negative');

        new ServerConfig(
            enableHsts: true,
            hstsMaxAge: -1,
        );
    }

    public function testHstsDisabledWithNegativeMaxAgeDoesNotThrow(): void
    {
        $config = new ServerConfig(
            enableHsts: false,
            hstsMaxAge: -1,
        );

        $this->assertSame(-1, $config->hstsMaxAge);
    }
}
