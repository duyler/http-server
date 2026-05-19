<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Contract\MetricsInterface;
use Duyler\HttpServer\Contract\RequestLifecycleInterface;
use Duyler\HttpServer\Contract\ServerLifecycleInterface;
use Duyler\HttpServer\Contract\WorkerPoolIntegrationInterface;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\ServerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(Server::class)]
class ServerInterfaceComplianceTest extends TestCase
{
    #[Test]
    public function server_implements_server_interface(): void
    {
        $reflection = new ReflectionClass(Server::class);
        $this->assertTrue($reflection->implementsInterface(ServerInterface::class));
    }

    #[Test]
    public function server_implements_request_lifecycle_interface(): void
    {
        $reflection = new ReflectionClass(Server::class);
        $this->assertTrue($reflection->implementsInterface(RequestLifecycleInterface::class));
    }

    #[Test]
    public function server_implements_server_lifecycle_interface(): void
    {
        $reflection = new ReflectionClass(Server::class);
        $this->assertTrue($reflection->implementsInterface(ServerLifecycleInterface::class));
    }

    #[Test]
    public function server_implements_worker_pool_integration_interface(): void
    {
        $reflection = new ReflectionClass(Server::class);
        $this->assertTrue($reflection->implementsInterface(WorkerPoolIntegrationInterface::class));
    }

    #[Test]
    public function server_implements_metrics_interface(): void
    {
        $reflection = new ReflectionClass(Server::class);
        $this->assertTrue($reflection->implementsInterface(MetricsInterface::class));
    }

    #[Test]
    public function server_interface_extends_all_sub_interfaces(): void
    {
        $reflection = new ReflectionClass(ServerInterface::class);
        $this->assertTrue($reflection->implementsInterface(RequestLifecycleInterface::class));
        $this->assertTrue($reflection->implementsInterface(ServerLifecycleInterface::class));
        $this->assertTrue($reflection->implementsInterface(WorkerPoolIntegrationInterface::class));
        $this->assertTrue($reflection->implementsInterface(MetricsInterface::class));
    }

    #[Test]
    public function get_socket_resource_is_defined_in_interface(): void
    {
        $method = new ReflectionMethod(WorkerPoolIntegrationInterface::class, 'getSocketResource');

        $this->assertTrue($method->isPublic());
        $this->assertSame('mixed', (string) $method->getReturnType());
    }

    #[Test]
    public function set_external_socket_resource_is_defined_in_interface(): void
    {
        $method = new ReflectionMethod(WorkerPoolIntegrationInterface::class, 'setExternalSocketResource');
        $this->assertTrue($method->isPublic());
        $this->assertSame('void', (string) $method->getReturnType());
    }

    #[Test]
    public function all_interface_methods_are_implemented(): void
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
