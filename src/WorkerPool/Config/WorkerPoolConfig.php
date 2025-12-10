<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Config;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Util\SystemInfo;
use InvalidArgumentException;

enum BalancerType: string
{
    case LeastConnections = 'least_connections';
    case RoundRobin = 'round_robin';
    case Weighted = 'weighted';
}

readonly class WorkerPoolConfig
{
    public int $workerCount;

    public function __construct(
        public ServerConfig $serverConfig,
        int $workerCount = 0,
        public BalancerType $balancer = BalancerType::LeastConnections,
        public int $backlog = 128,
        public int $maxQueueSize = 1000,
        public bool $enableStickySession = false,
        public bool $enableGracefulReload = false,
        public bool $autoRestart = true,
        public int $restartDelay = 1,
        public int $fallbackCpuCores = 4,
    ) {
        if ($workerCount === 0) {
            $systemInfo = new SystemInfo();
            $this->workerCount = $systemInfo->getCpuCores($this->fallbackCpuCores);
        } else {
            $this->workerCount = $workerCount;
        }

        $this->validate();
    }

    private function validate(): void
    {
        if ($this->workerCount < 1) {
            throw new InvalidArgumentException(
                "Worker count must be positive, got: {$this->workerCount}",
            );
        }

        if ($this->workerCount > 1024) {
            throw new InvalidArgumentException(
                "Worker count too large (max 1024), got: {$this->workerCount}",
            );
        }

        if ($this->backlog < 1) {
            throw new InvalidArgumentException('Backlog must be positive');
        }

        if ($this->maxQueueSize < 1) {
            throw new InvalidArgumentException('Max queue size must be positive');
        }

        if ($this->restartDelay < 0) {
            throw new InvalidArgumentException('Restart delay must be non-negative');
        }

        if ($this->fallbackCpuCores < 1) {
            throw new InvalidArgumentException('Fallback CPU cores must be positive');
        }
    }

    public static function auto(
        ServerConfig $serverConfig,
        BalancerType $balancer = BalancerType::LeastConnections,
    ): self {
        return new self(
            serverConfig: $serverConfig,
            workerCount: 0,
            balancer: $balancer,
        );
    }
}
