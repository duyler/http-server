<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Util;

use Duyler\HttpServer\WorkerPool\Util\SystemInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SystemInfoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SystemInfo::resetCache();
    }
    
    #[Test]
    public function returns_positive_number(): void
    {
        $systemInfo = new SystemInfo();
        $cores = $systemInfo->getCpuCores();
        
        $this->assertGreaterThan(0, $cores);
        $this->assertIsInt($cores);
    }
    
    #[Test]
    public function uses_cache(): void
    {
        $systemInfo = new SystemInfo();
        
        $cores1 = $systemInfo->getCpuCores();
        $cores2 = $systemInfo->getCpuCores();
        
        $this->assertSame($cores1, $cores2);
    }
    
    #[Test]
    public function uses_fallback_when_detection_fails(): void
    {
        $systemInfo = new SystemInfo();
        
        $cores = $systemInfo->getCpuCores(fallback: 8);
        
        $this->assertGreaterThanOrEqual(1, $cores);
    }
    
    #[Test]
    public function returns_os_info(): void
    {
        $systemInfo = new SystemInfo();
        $info = $systemInfo->getOsInfo();
        
        $this->assertIsArray($info);
        $this->assertArrayHasKey('os', $info);
        $this->assertArrayHasKey('os_family', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('sapi', $info);
        $this->assertArrayHasKey('cpu_cores', $info);
        
        $this->assertGreaterThan(0, $info['cpu_cores']);
    }
    
    #[Test]
    public function resets_cache(): void
    {
        $systemInfo = new SystemInfo();
        
        $cores1 = $systemInfo->getCpuCores();
        
        SystemInfo::resetCache();
        
        $cores2 = $systemInfo->getCpuCores();
        
        $this->assertSame($cores1, $cores2);
    }
}
