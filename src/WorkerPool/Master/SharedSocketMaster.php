<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use Psr\Log\LoggerInterface;
use Socket;

/**
 * Shared Socket Master with kernel load balancing
 *
 * Architecture:
 * - Each worker has its own socket on same port (SO_REUSEPORT)
 * - Kernel automatically distributes connections
 * - No IPC overhead
 * - Simple and reliable
 *
 * Requirements:
 * - SO_REUSEPORT support (Linux, Docker, macOS via Docker)
 *
 * Use when:
 * - Want simple architecture
 * - Kernel load balancing is sufficient
 * - Maximum compatibility needed
 * - Running in Docker or need macOS support
 *
 * @see CentralizedMaster For centralized architecture with custom load balancing
 */
class SharedSocketMaster extends AbstractMaster
{
    private WorkerManager $workerManager;

    public function __construct(
        WorkerPoolConfig $config,
        private readonly ServerConfig $serverConfig,
        private readonly WorkerCallbackInterface $workerCallback,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($config, $logger);

        $this->workerManager = new WorkerManager($this->logger);
    }

    public function start(): void
    {
        $this->logger->info('Starting with SO_REUSEPORT architecture', [
            'workers' => $this->config->workerCount,
        ]);

        for ($i = 1; $i <= $this->config->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->run();
    }

    public function stop(): void
    {
        parent::stop();
        $this->workerManager->stopAll();
    }

    protected function run(): void
    {
        $this->logger->info('Entering main loop');

        while (!$this->shouldStop) {
            $this->signalHandler->dispatch();
            $this->checkWorkers();
            usleep($this->config->pollInterval);
        }

        $this->logger->info('Exiting main loop, waiting for workers');
        $this->waitForWorkers();
    }

    protected function spawnWorker(int $workerId): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new WorkerPoolException('Failed to fork worker process');
        }

        if ($pid === 0) {
            $this->logger->info('Worker process started', [
                'worker_id' => $workerId,
                'pid' => getmypid(),
            ]);
            $this->runWorkerProcess($workerId);
            $this->logger->info('Worker process exiting', ['worker_id' => $workerId]);
            exit(0);
        }

        $this->workers[$workerId] = new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
        );

        $this->logger->info('Worker spawned', ['worker_id' => $workerId, 'pid' => $pid]);
    }

    private function runWorkerProcess(int $workerId): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            $this->logger->error('Failed to create socket', ['worker_id' => $workerId]);
            exit(1);
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->logger->error('Failed to set SO_REUSEADDR', ['worker_id' => $workerId]);
            exit(1);
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            $this->logger->error('Failed to set SO_REUSEPORT', ['worker_id' => $workerId]);
            exit(1);
        }

        $host = $this->serverConfig->host;
        $port = $this->serverConfig->port;

        if (socket_bind($socket, $host, $port) === false) {
            $error = socket_strerror(socket_last_error($socket));
            $this->logger->error('Failed to bind socket', [
                'worker_id' => $workerId,
                'host' => $host,
                'port' => $port,
                'error' => $error,
            ]);
            exit(1);
        }

        if (!socket_listen($socket, 128)) {
            $this->logger->error('Failed to listen', [
                'worker_id' => $workerId,
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
            exit(1);
        }

        $this->logger->info('Worker listening', [
            'worker_id' => $workerId,
            'host' => $host,
            'port' => $port,
        ]);

        socket_set_nonblock($socket);

        /** @phpstan-ignore-next-line */
        while (true) {
            $clientSocket = socket_accept($socket);

            if ($clientSocket !== false) {
                $this->logger->debug('Worker accepted connection', ['worker_id' => $workerId]);

                $clientIp = '';
                socket_getpeername($clientSocket, $clientIp);

                $this->workerCallback->handle($clientSocket, [
                    'worker_id' => $workerId,
                    'client_ip' => $clientIp,
                ]);
            }

            usleep(1000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $activeWorkers = 0;
        $totalConnections = 0;

        foreach ($this->workers as $worker) {
            if ($worker->isAlive()) {
                $activeWorkers++;
                $totalConnections += $worker->connections;
            }
        }

        return [
            'architecture' => 'shared_socket',
            'total_workers' => count($this->workers),
            'active_workers' => $activeWorkers,
            'total_connections' => $totalConnections,
            'is_running' => $this->isRunning(),
        ];
    }
}
