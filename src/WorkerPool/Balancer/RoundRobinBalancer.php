<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Balancer;

use Override;

class RoundRobinBalancer implements BalancerInterface
{
    private int $currentIndex = 0;

    /**
     * @var array<int>
     */
    private array $workerIds = [];

    #[Override]
    public function selectWorker(array $connections): ?int
    {
        if ($connections === []) {
            return null;
        }

        $this->workerIds = array_keys($connections);

        if ($this->currentIndex >= count($this->workerIds)) {
            $this->currentIndex = 0;
        }

        $workerId = $this->workerIds[$this->currentIndex];
        $this->currentIndex++;

        return $workerId;
    }

    #[Override]
    public function onConnectionEstablished(int $workerId): void {}

    #[Override]
    public function onConnectionClosed(int $workerId): void {}

    #[Override]
    public function reset(): void
    {
        $this->currentIndex = 0;
        $this->workerIds = [];
    }

    public function getCurrentIndex(): int
    {
        return $this->currentIndex;
    }
}
