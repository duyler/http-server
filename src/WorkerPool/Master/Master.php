<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Balancer\BalancerInterface;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Duyler\HttpServer\WorkerPool\IPC\FdPasser;
use Duyler\HttpServer\WorkerPool\IPC\UnixSocketChannel;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Duyler\HttpServer\WorkerPool\Signal\SignalHandler;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use Socket;
use Throwable;

class Master
{
    private bool $shouldStop = false;

    /**
     * @var array<int, ProcessInfo>
     */
    private array $workers = [];

    /**
     * @var array<int, UnixSocketChannel>
     */
    private array $workerChannels = [];

    /**
     * @var array<int, Socket>
     */
    private array $workerSockets = [];

    private SignalHandler $signalHandler;
    private ?SocketManager $socketManager = null;
    private ?ConnectionQueue $connectionQueue = null;
    private FdPasser $fdPasser;

    public function __construct(
        private readonly WorkerPoolConfig $config,
        private readonly BalancerInterface $balancer,
        private readonly ?ServerConfig $serverConfig = null,
        private readonly ?WorkerCallbackInterface $workerCallback = null,
    ) {
        $this->signalHandler = new SignalHandler();
        $this->fdPasser = new FdPasser();
        $this->setupSignals();

        if ($this->serverConfig !== null) {
            $this->socketManager = new SocketManager($this->serverConfig);
            $this->connectionQueue = new ConnectionQueue(maxSize: 1000);
        }
    }

    public function start(): void
    {
        if ($this->socketManager !== null) {
            error_log("[Master] Starting socket manager...");
            $this->socketManager->listen();
            error_log("[Master] Socket manager listening on port");
        } else {
            error_log("[Master] WARNING: No socket manager configured!");
        }

        error_log("[Master] Spawning {$this->config->workerCount} workers...");
        for ($i = 1; $i <= $this->config->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        error_log("[Master] Entering main loop...");
        $this->run();
    }

    public function stop(): void
    {
        $this->shouldStop = true;

        if ($this->socketManager !== null) {
            $this->socketManager->close();
        }

        if ($this->connectionQueue !== null) {
            $this->connectionQueue->clear();
        }

        foreach ($this->workerChannels as $channel) {
            $channel->close();
        }

        foreach ($this->workers as $worker) {
            if ($worker->pid > 0) {
                posix_kill($worker->pid, SIGTERM);
            }
        }
    }

    /**
     * @return array<int, ProcessInfo>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    private function run(): void
    {
        $iteration = 0;
        while (!$this->shouldStop) {
            $this->signalHandler->dispatch();

            if ($this->socketManager !== null) {
                $this->acceptConnections();
                $this->processQueue();
            } else {
                if ($iteration === 0) {
                    error_log("[Master] No socket manager in main loop!");
                }
            }

            $this->checkWorkers();
            usleep(10000);
            
            $iteration++;
            if ($iteration % 100 === 0) {
                error_log("[Master] Main loop iteration $iteration, workers alive: " . count($this->workers));
            }
        }

        error_log("[Master] Exiting main loop, waiting for workers...");
        $this->waitForWorkers();
    }

    private function acceptConnections(): void
    {
        static $callCount = 0;
        $callCount++;
        
        if ($callCount % 1000 === 0) {
            error_log("[Master] acceptConnections() called $callCount times");
        }

        if ($this->socketManager === null || $this->connectionQueue === null) {
            if ($callCount === 1) {
                error_log("[Master] ERROR: socketManager or connectionQueue is null!");
            }
            return;
        }

        for ($i = 0; $i < 10; $i++) {
            $clientSocket = $this->socketManager->accept();

            if ($clientSocket === null) {
                // Это нормально для non-blocking socket
                break;
            }

            error_log("[Master] ✅ Accepted new connection!");

            if ($this->connectionQueue->isFull()) {
                error_log("[Master] Queue full, rejecting connection");
                socket_close($clientSocket);
                break;
            }

            $this->connectionQueue->enqueue($clientSocket);
            error_log("[Master] Connection queued, queue size: " . $this->connectionQueue->size());
        }
    }

    private function processQueue(): void
    {
        $queue = $this->connectionQueue;

        if ($queue === null) {
            return;
        }

        while (!$queue->isEmpty()) {
            $clientSocket = $queue->dequeue();

            if ($clientSocket === null) {
                break;
            }

            $connections = $this->getWorkerConnections();
            $workerId = $this->balancer->selectWorker($connections);

            if ($workerId === null) {
                $queue->enqueue($clientSocket);
                break;
            }

            $this->passConnectionToWorker($workerId, $clientSocket);
        }
    }

    /**
     * @return array<int, int>
     */
    private function getWorkerConnections(): array
    {
        $connections = [];

        foreach ($this->workers as $workerId => $worker) {
            if ($worker->state === ProcessState::Ready || $worker->state === ProcessState::Busy) {
                $connections[$workerId] = $worker->connections;
            }
        }

        return $connections;
    }

    private function passConnectionToWorker(int $workerId, Socket $clientSocket): void
    {
        if (!isset($this->workerSockets[$workerId])) {
            error_log("[Master] Worker $workerId socket not found");
            socket_close($clientSocket);
            return;
        }

        $clientIp = '';
        socket_getpeername($clientSocket, $clientIp);

        error_log("[Master] Passing FD to worker $workerId");

        try {
            $this->fdPasser->sendFd(
                controlSocket: $this->workerSockets[$workerId],
                fdToSend: $clientSocket,
                metadata: [
                    'worker_id' => $workerId,
                    'client_ip' => $clientIp,
                    'timestamp' => microtime(true),
                ],
            );

            error_log("[Master] FD passed successfully to worker $workerId");

            $worker = $this->workers[$workerId];
            $this->workers[$workerId] = $worker->withConnections($worker->connections + 1);
            $this->balancer->onConnectionEstablished($workerId);
        } catch (Throwable $e) {
            error_log("[Master] Failed to pass FD to worker $workerId: " . $e->getMessage());
            socket_close($clientSocket);
        }
    }

    private function spawnWorker(int $workerId): void
    {
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        [$masterSocket, $workerSocket] = $pair;

        $pid = pcntl_fork();

        if ($pid === -1) {
            socket_close($masterSocket);
            socket_close($workerSocket);
            throw new WorkerPoolException('Failed to fork worker process');
        }

        if ($pid === 0) {
            // Close master's IPC socket (не нужен worker'у)
            socket_close($masterSocket);

            // КРИТИЧЕСКИ ВАЖНО: Отсоединить (но НЕ закрывать!) master socket в worker!
            // При fork worker получает копию file descriptor, но он указывает
            // на тот же системный ресурс. Если worker закроет socket,
            // он закроется для ВСЕХ процессов, включая Master!
            // Поэтому просто забываем о socket (устанавливаем null).
            if ($this->socketManager !== null) {
                $this->socketManager->detachFromWorker();
                $this->socketManager = null;
            }

            error_log("[Worker $workerId] Process started, PID: " . getmypid());
            $this->runWorkerProcess($workerId, $workerSocket);
            error_log("[Worker $workerId] Process exiting");
            exit(0);
        }

        socket_close($workerSocket);

        $this->workers[$workerId] = new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
        );

        $this->workerSockets[$workerId] = $masterSocket;
        error_log("[Master] Worker $workerId spawned with PID: $pid");
    }

    private function runWorkerProcess(int $workerId, Socket $ipcSocket): void
    {
        $running = true;
        error_log("[Worker $workerId] Entering receive loop");

        /** @phpstan-ignore-next-line */
        while ($running) {
            $result = $this->fdPasser->receiveFd($ipcSocket);

            if ($result === null) {
                usleep(10000);
                continue;
            }

            error_log("[Worker $workerId] Received FD from master");

            $clientSocket = $result['fd'];
            $metadata = $result['metadata'];

            if ($this->workerCallback !== null) {
                $this->workerCallback->handle($clientSocket, $metadata);
            } else {
                error_log("[Worker $workerId] No callback, closing socket");
                socket_close($clientSocket);
            }
        }
    }

    private function checkWorkers(): void
    {
        foreach ($this->workers as $workerId => $worker) {
            if (!$worker->isAlive()) {
                unset($this->workers[$workerId]);

                if (!$this->shouldStop && $this->config->autoRestart) {
                    sleep($this->config->restartDelay);
                    $this->spawnWorker($workerId);
                }
            }
        }
    }

    private function waitForWorkers(): void
    {
        $timeout = 10;
        $start = time();

        while (count($this->workers) > 0) {
            foreach ($this->workers as $workerId => $worker) {
                $status = 0;
                $result = pcntl_waitpid($worker->pid, $status, WNOHANG);

                if ($result > 0 || !$worker->isAlive()) {
                    unset($this->workers[$workerId]);
                }
            }

            if (time() - $start > $timeout) {
                foreach ($this->workers as $worker) {
                    if ($worker->pid > 0) {
                        posix_kill($worker->pid, SIGKILL);
                    }
                }
                break;
            }

            usleep(100000);
        }
    }

    private function setupSignals(): void
    {
        $this->signalHandler->register(SIGTERM, function (): void {
            $this->stop();
        });

        $this->signalHandler->register(SIGINT, function (): void {
            $this->stop();
        });

        if (defined('SIGUSR1')) {
            $this->signalHandler->register(SIGUSR1, function (): void {
                $this->collectMetrics();
            });
        }
    }

    private function collectMetrics(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->pid > 0 && defined('SIGUSR1')) {
                posix_kill($worker->pid, SIGUSR1);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        $aliveWorkers = 0;
        $totalConnections = 0;
        $totalRequests = 0;

        foreach ($this->workers as $worker) {
            if ($worker->isAlive()) {
                $aliveWorkers++;
                $totalConnections += $worker->connections;
                $totalRequests += $worker->totalRequests;
            }
        }

        return [
            'total_workers' => $this->config->workerCount,
            'alive_workers' => $aliveWorkers,
            'total_connections' => $totalConnections,
            'total_requests' => $totalRequests,
        ];
    }

    public function selectWorker(): ?int
    {
        $connections = [];

        foreach ($this->workers as $worker) {
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
