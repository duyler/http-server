<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Balancer;

class LeastConnectionsBalancer implements BalancerInterface
{
    /**
     * @var array<int, int>
     */
    private array $connections = [];

    public function selectWorker(array $connections): ?int
    {
        if ($connections === []) {
            return null;
        }

        $this->connections = $connections;

        $minConnections = min($connections);
        $workersWithMinConnections = array_keys($connections, $minConnections, true);

        if ($workersWithMinConnections === []) {
            return null;
        }

        return $workersWithMinConnections[array_rand($workersWithMinConnections)];
    }

    public function onConnectionEstablished(int $workerId): void
    {
        if (!isset($this->connections[$workerId])) {
            $this->connections[$workerId] = 0;
        }

        $this->connections[$workerId]++;
    }

    public function onConnectionClosed(int $workerId): void
    {
        if (!isset($this->connections[$workerId])) {
            return;
        }

        $this->connections[$workerId]--;

        if ($this->connections[$workerId] < 0) {
            $this->connections[$workerId] = 0;
        }
    }

    public function reset(): void
    {
        $this->connections = [];
    }

    /**
     * @return array<int, int>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }
}
