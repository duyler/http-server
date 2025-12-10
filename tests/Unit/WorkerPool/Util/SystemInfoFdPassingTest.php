<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Util;

use Duyler\HttpServer\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemInfoFdPassingTest extends TestCase
{
    private SystemInfo $systemInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemInfo = new SystemInfo();
    }

    #[Test]
    public function supports_fd_passing_on_linux_with_required_functions(): void
    {
        $result = $this->systemInfo->supportsFdPassing();

        if (PHP_OS_FAMILY === 'Linux' && function_exists('socket_sendmsg') && defined('SCM_RIGHTS')) {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }

    #[Test]
    public function does_not_support_fd_passing_on_non_linux(): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $this->markTestSkipped('Test only for non-Linux platforms');
        }

        $result = $this->systemInfo->supportsFdPassing();

        $this->assertFalse($result);
    }

    #[Test]
    public function checks_reuse_port_support(): void
    {
        $result = $this->systemInfo->supportsReusePort();

        $this->assertIsBool($result);

        if (defined('SO_REUSEPORT')) {
            $this->assertTrue($result);
        } else {
            $this->assertFalse($result);
        }
    }
}
