<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\CentralizedMaster;
use Duyler\HttpServer\WorkerPool\Master\MasterFactory;
use Duyler\HttpServer\WorkerPool\Master\MasterInterface;
use Duyler\HttpServer\WorkerPool\Master\SharedSocketMaster;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Socket;

final class MasterFactoryTest extends TestCase
{
    private WorkerPoolConfig $config;
    private ServerConfig $serverConfig;
    private WorkerCallbackInterface $callback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 9999,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $this->serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->callback = new class implements WorkerCallbackInterface {
            public function handle(Socket $clientSocket, array $metadata): void
            {
                socket_close($clientSocket);
            }
        };
    }

    #[Test]
    public function creates_master_instance(): void
    {
        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function creates_shared_socket_master_when_fd_passing_not_supported(): void
    {
        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            balancer: null,
        );

        $this->assertInstanceOf(SharedSocketMaster::class, $master);
    }

    #[Test]
    public function creates_centralized_master_when_fd_passing_supported_and_balancer_provided(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('FD Passing only supported on Linux');
        }

        if (!function_exists('socket_sendmsg') || !defined('SCM_RIGHTS')) {
            $this->markTestSkipped('FD Passing not available');
        }

        $balancer = new LeastConnectionsBalancer();

        $master = MasterFactory::create(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
            balancer: $balancer,
        );

        $this->assertInstanceOf(CentralizedMaster::class, $master);
    }

    #[Test]
    public function creates_recommended_master(): void
    {
        $master = MasterFactory::createRecommended(
            config: $this->config,
            serverConfig: $this->serverConfig,
            workerCallback: $this->callback,
        );

        $this->assertInstanceOf(MasterInterface::class, $master);
    }

    #[Test]
    public function returns_recommended_master_name(): void
    {
        $recommendation = MasterFactory::recommendedMaster();

        $this->assertIsString($recommendation);
        $this->assertNotEmpty($recommendation);
        $this->assertStringContainsString('Master', $recommendation);
    }

    #[Test]
    public function returns_comparison_array(): void
    {
        $comparison = MasterFactory::getComparison();

        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('SharedSocketMaster', $comparison);
        $this->assertArrayHasKey('CentralizedMaster', $comparison);

        $this->assertArrayHasKey('architecture', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('load_balancing', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('requirements', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('platforms', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('complexity', $comparison['SharedSocketMaster']);
        $this->assertArrayHasKey('use_case', $comparison['SharedSocketMaster']);

        $this->assertArrayHasKey('architecture', $comparison['CentralizedMaster']);
        $this->assertArrayHasKey('load_balancing', $comparison['CentralizedMaster']);
    }

    #[Test]
    public function comparison_provides_useful_information(): void
    {
        $comparison = MasterFactory::getComparison();

        $sharedSocket = $comparison['SharedSocketMaster'];
        $centralized = $comparison['CentralizedMaster'];

        $this->assertStringContainsString('Distributed', $sharedSocket['architecture']);
        $this->assertStringContainsString('Kernel', $sharedSocket['load_balancing']);

        $this->assertStringContainsString('Centralized', $centralized['architecture']);
        $this->assertStringContainsString('Custom', $centralized['load_balancing']);
    }
}
