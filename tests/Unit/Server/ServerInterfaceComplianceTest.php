<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Server;
use Duyler\HttpServer\ServerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(Server::class)]
class ServerInterfaceComplianceTest extends TestCase
{
    public function testServerImplementsServerInterface(): void
    {
        $reflection = new ReflectionClass(Server::class);
        $this->assertTrue($reflection->implementsInterface(ServerInterface::class));
    }

    public function testGetSocketResourceIsDefinedInInterface(): void
    {
        $method = new ReflectionMethod(ServerInterface::class, 'getSocketResource');

        $this->assertTrue($method->isPublic());
        $this->assertSame('mixed', (string) $method->getReturnType());
    }

    public function testSetExternalSocketResourceIsDefinedInInterface(): void
    {
        $method = new ReflectionMethod(ServerInterface::class, 'setExternalSocketResource');
        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    public function testAllInterfaceMethodsAreImplemented(): void
    {
        $interfaceMethods = get_class_methods(ServerInterface::class);
        $serverMethods = get_class_methods(Server::class);

        foreach ($interfaceMethods as $method) {
            $this->assertContains(
                $method,
                $serverMethods,
                "Server must implement $method from ServerInterface",
            );
        }
    }
}
