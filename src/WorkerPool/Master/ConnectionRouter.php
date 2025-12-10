<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\WorkerPool\Balancer\BalancerInterface;
use Duyler\HttpServer\WorkerPool\IPC\FdPasser;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;
use Throwable;

final class ConnectionRouter
{
    private LoggerInterface $logger;
    private FdPasser $fdPasser;

    public function __construct(
        private readonly BalancerInterface $balancer,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->fdPasser = new FdPasser($this->logger);
    }

    /**
     * @param Socket $clientSocket
     * @param array<int, ProcessInfo> $workers
     * @param array<int, Socket> $workerSockets
     * @param array<string, mixed> $metadata
     */
    public function route(
        Socket $clientSocket,
        array $workers,
        array $workerSockets,
        array $metadata = [],
    ): bool {
        $workerId = $this->selectWorker($workers);

        if ($workerId === null) {
            $this->logger->warning('No available workers');
            socket_close($clientSocket);
            return false;
        }

        if (!isset($workerSockets[$workerId])) {
            $this->logger->error('Worker socket not found', ['worker_id' => $workerId]);
            socket_close($clientSocket);
            return false;
        }

        $clientIp = '';
        socket_getpeername($clientSocket, $clientIp);

        $this->logger->debug('Passing FD to worker', ['worker_id' => $workerId]);

        try {
            $this->fdPasser->sendFd(
                controlSocket: $workerSockets[$workerId],
                fdToSend: $clientSocket,
                metadata: array_merge($metadata, [
                    'worker_id' => $workerId,
                    'client_ip' => $clientIp,
                ]),
            );

            $this->logger->debug('FD passed successfully', ['worker_id' => $workerId]);

            $this->balancer->onConnectionEstablished($workerId);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to pass FD to worker', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
            socket_close($clientSocket);

            return false;
        }
    }

    /**
     * @param array<int, ProcessInfo> $workers
     */
    private function selectWorker(array $workers): ?int
    {
        $connections = [];

        foreach ($workers as $worker) {
            if ($worker->isAlive() && $worker->state === ProcessState::Ready) {
                $connections[$worker->workerId] = $worker->connections;
            }
        }

        return $this->balancer->selectWorker($connections);
    }

    public function getBalancer(): BalancerInterface
    {
        return $this->balancer;
    }
}
