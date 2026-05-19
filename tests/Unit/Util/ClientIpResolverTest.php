<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Util;

use Duyler\HttpServer\Util\ClientIpResolver;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientIpResolverTest extends TestCase
{
    #[Test]
    public function resolves_from_remote_addr(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'REMOTE_ADDR' => '192.168.1.1',
        ]);

        self::assertSame('192.168.1.1', ClientIpResolver::resolve($request));
    }

    #[Test]
    public function returns_unknown_when_no_headers(): void
    {
        $request = new ServerRequest('GET', '/test');

        self::assertSame('unknown', ClientIpResolver::resolve($request));
    }

    #[Test]
    public function ignores_x_forwarded_for_without_trusted_proxies(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
            'REMOTE_ADDR' => '192.168.1.1',
        ]);

        self::assertSame('192.168.1.1', ClientIpResolver::resolve($request));
    }

    #[Test]
    public function ignores_x_real_ip_without_trusted_proxies(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_REAL_IP' => '203.0.113.2',
            'REMOTE_ADDR' => '192.168.1.1',
        ]);

        self::assertSame('192.168.1.1', ClientIpResolver::resolve($request));
    }

    #[Test]
    public function resolves_from_x_forwarded_for_with_trusted_proxy(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1, 70.41.3.18, 150.172.238.178',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('150.172.238.178', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function resolves_from_x_real_ip_with_trusted_proxy(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_REAL_IP' => '203.0.113.2',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('203.0.113.2', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function prefers_x_forwarded_for_over_x_real_ip_with_trusted_proxy(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
            'HTTP_X_REAL_IP' => '203.0.113.99',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('203.0.113.50', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function ignores_invalid_x_forwarded_for_with_trusted_proxy(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('10.0.0.1', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function ignores_headers_from_untrusted_proxy(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
            'REMOTE_ADDR' => '192.168.1.1',
        ]);

        self::assertSame('192.168.1.1', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function handles_ipv6_address_with_trusted_proxy(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '::1',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('::1', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function falls_back_to_remote_addr_with_trusted_proxy_when_no_headers(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('10.0.0.1', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function prevents_ip_spoofing_via_leftmost_injection(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => 'spoofed-ip, 203.0.113.1',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('203.0.113.1', ClientIpResolver::resolve($request, ['10.0.0.1']));
    }

    #[Test]
    public function walks_right_to_left_skipping_trusted_proxies(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1, 10.0.0.2',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('203.0.113.1', ClientIpResolver::resolve($request, ['10.0.0.1', '10.0.0.2']));
    }

    #[Test]
    public function falls_back_to_remote_addr_when_all_xff_ips_are_trusted(): void
    {
        $request = new ServerRequest('GET', '/test', serverParams: [
            'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 10.0.0.3',
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        self::assertSame('10.0.0.1', ClientIpResolver::resolve($request, ['10.0.0.1', '10.0.0.2', '10.0.0.3']));
    }
}
