<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Balancer\BalancerInterface;
use Duyler\HttpServer\WorkerPool\Balancer\LeastConnectionsBalancer;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Util\SystemInfo;
use Duyler\HttpServer\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class MasterFactory
{
    public static function create(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?BalancerInterface $balancer = null,
        ?LoggerInterface $logger = null,
    ): MasterInterface {
        if ($workerCallback === null && $eventDrivenWorker === null) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

        $systemInfo = new SystemInfo();

        if ($systemInfo->supportsFdPassing() && $balancer !== null) {
            return new CentralizedMaster(
                config: $config,
                balancer: $balancer,
                serverConfig: $serverConfig,
                workerCallback: $workerCallback,
                eventDrivenWorker: $eventDrivenWorker,
                logger: $logger ?? new \Psr\Log\NullLogger(),
            );
        }

        return new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
            eventDrivenWorker: $eventDrivenWorker,
            logger: $logger ?? new \Psr\Log\NullLogger(),
        );
    }

    public static function createRecommended(
        WorkerPoolConfig $config,
        ServerConfig $serverConfig,
        ?WorkerCallbackInterface $workerCallback = null,
        ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    ): MasterInterface {
        if ($workerCallback === null && $eventDrivenWorker === null) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

        $systemInfo = new SystemInfo();

        if ($systemInfo->supportsFdPassing()) {
            $balancer = new LeastConnectionsBalancer();

            return new CentralizedMaster(
                config: $config,
                balancer: $balancer,
                serverConfig: $serverConfig,
                workerCallback: $workerCallback,
                eventDrivenWorker: $eventDrivenWorker,
                logger: $logger ?? new \Psr\Log\NullLogger(),
            );
        }

        return new SharedSocketMaster(
            config: $config,
            serverConfig: $serverConfig,
            workerCallback: $workerCallback,
            eventDrivenWorker: $eventDrivenWorker,
            logger: $logger ?? new \Psr\Log\NullLogger(),
        );
    }

    public static function recommendedMaster(): string
    {
        $systemInfo = new SystemInfo();

        if ($systemInfo->supportsFdPassing()) {
            return 'CentralizedMaster - Centralized queue with custom load balancing';
        }

        return 'SharedSocketMaster - Distributed architecture with kernel load balancing';
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getComparison(): array
    {
        return [
            'SharedSocketMaster' => [
                'architecture' => 'Distributed (each worker has socket)',
                'load_balancing' => 'Kernel (automatic)',
                'requirements' => 'SO_REUSEPORT',
                'platforms' => 'Linux, Docker, macOS (via Docker)',
                'complexity' => 'Low',
                'use_case' => 'Simple setup, kernel balancing sufficient',
            ],
            'CentralizedMaster' => [
                'architecture' => 'Centralized (master accepts, distributes via IPC)',
                'load_balancing' => 'Custom (Least Connections, Round Robin)',
                'requirements' => 'SCM_RIGHTS (socket_sendmsg)',
                'platforms' => 'Linux only',
                'complexity' => 'High',
                'use_case' => 'Custom balancing, sticky sessions needed',
            ],
        ];
    }
}
