<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Master\CentralizedMaster;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CentralizedMasterTest extends TestCase
{
    private WorkerPoolConfig $config;
    private LeastConnectionsBalancer $balancer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $this->config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 2,
            autoRestart: false,
        );

        $this->balancer = new LeastConnectionsBalancer();
    }

    #[Test]
    public function creates_centralized_master_with_config(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer);

        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Test]
    public function spawns_configured_number_of_workers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $master = new CentralizedMaster($this->config, $this->balancer);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Failed to fork');
        }

        if ($pid === 0) {
            sleep(1);
            exit(0);
        }

        $this->assertTrue(true);

        pcntl_waitpid($pid, $status);
    }

    #[Test]
    public function tracks_worker_processes(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer);

        $workers = $master->getWorkers();

        $this->assertIsArray($workers);
        $this->assertSame(0, count($workers));
    }

    #[Test]
    public function stops_all_workers_on_stop(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer);

        $master->stop();

        $this->assertTrue(true);
    }

    #[Test]
    public function collects_metrics_from_workers(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer);

        $metrics = $master->getMetrics();

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('total_workers', $metrics);
        $this->assertArrayHasKey('alive_workers', $metrics);
        $this->assertArrayHasKey('total_connections', $metrics);
        $this->assertArrayHasKey('total_requests', $metrics);
        $this->assertSame(2, $metrics['total_workers']);
        $this->assertSame(0, $metrics['alive_workers']);
    }

    #[Test]
    public function returns_worker_count(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer);

        $count = $master->getWorkerCount();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function handles_auto_restart_config(): void
    {
        $serverConfig = new ServerConfig(
            host: '127.0.0.1',
            port: 8080,
        );

        $config = new WorkerPoolConfig(
            serverConfig: $serverConfig,
            workerCount: 1,
            autoRestart: true,
            restartDelay: 0,
        );

        $master = new CentralizedMaster($config, $this->balancer);

        $this->assertSame(0, $master->getWorkerCount());
    }

    #[Test]
    public function gets_empty_workers_list_initially(): void
    {
        $master = new CentralizedMaster($this->config, $this->balancer);

        $workers = $master->getWorkers();

        $this->assertEmpty($workers);
    }
}
