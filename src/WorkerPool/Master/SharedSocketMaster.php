<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Duyler\HttpServer\WorkerPool\Signal\SignalHandler;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use Socket;

/**
 * Shared Socket Master - использует SO_REUSEPORT
 * 
 * Все worker процессы слушают на одном порту.
 * Kernel автоматически балансирует нагрузку.
 * 
 * Преимущества:
 * - Работает везде (Docker, macOS, Linux)
 * - Не требует SCM_RIGHTS
 * - Простая реализация
 * 
 * Недостатки:
 * - Нет Least Connections балансировки
 * - Нет Sticky Sessions
 * - Нет централизованной очереди
 */
class SharedSocketMaster
{
    private bool $shouldStop = false;

    /**
     * @var array<int, ProcessInfo>
     */
    private array $workers = [];

    private SignalHandler $signalHandler;

    public function __construct(
        private readonly WorkerPoolConfig $config,
        private readonly ServerConfig $serverConfig,
        private readonly WorkerCallbackInterface $workerCallback,
    ) {
        $this->signalHandler = new SignalHandler();
        $this->setupSignals();
    }

    public function start(): void
    {
        error_log("[SharedSocketMaster] Starting with SO_REUSEPORT architecture");
        error_log("[SharedSocketMaster] Workers: {$this->config->workerCount}");

        for ($i = 1; $i <= $this->config->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->run();
    }

    public function stop(): void
    {
        $this->shouldStop = true;

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

    private function run(): void
    {
        error_log("[SharedSocketMaster] Entering main loop...");

        while (!$this->shouldStop) {
            $this->signalHandler->dispatch();
            $this->checkWorkers();
            usleep(100000); // 100ms
        }

        error_log("[SharedSocketMaster] Exiting main loop, waiting for workers...");
        $this->waitForWorkers();
    }

    private function spawnWorker(int $workerId): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new WorkerPoolException('Failed to fork worker process');
        }

        if ($pid === 0) {
            error_log("[Worker $workerId] Process started, PID: " . getmypid());
            $this->runWorkerProcess($workerId);
            error_log("[Worker $workerId] Process exiting");
            exit(0);
        }

        $this->workers[$workerId] = new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
        );

        error_log("[SharedSocketMaster] Worker $workerId spawned with PID: $pid");
    }

    private function runWorkerProcess(int $workerId): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            error_log("[Worker $workerId] Failed to create socket");
            exit(1);
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (defined('SO_REUSEPORT')) {
            socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
        }

        $host = $this->serverConfig->host;
        $port = $this->serverConfig->port;

        if (socket_bind($socket, $host, $port) === false) {
            $error = socket_strerror(socket_last_error($socket));
            error_log("[Worker $workerId] Failed to bind to $host:$port: $error");
            exit(1);
        }

        if (!socket_listen($socket, 128)) {
            error_log("[Worker $workerId] Failed to listen: " . socket_strerror(socket_last_error($socket)));
            exit(1);
        }

        error_log("[Worker $workerId] ✅ Listening on $host:$port");

        socket_set_nonblock($socket);

        $running = true;

        pcntl_signal(SIGTERM, function () use (&$running): void {
            $running = false;
        });

        pcntl_signal(SIGINT, function () use (&$running): void {
            $running = false;
        });

        while ($running) {
            pcntl_signal_dispatch();

            $clientSocket = socket_accept($socket);

            if ($clientSocket !== false) {
                error_log("[Worker $workerId] ✅ Accepted connection");

                $clientIp = '';
                socket_getpeername($clientSocket, $clientIp);

                $this->workerCallback->handle($clientSocket, [
                    'worker_id' => $workerId,
                    'worker_pid' => getmypid(),
                    'client_ip' => $clientIp,
                ]);
            }

            usleep(1000);
        }

        error_log("[Worker $workerId] Shutting down gracefully");
    }

    private function checkWorkers(): void
    {
        foreach ($this->workers as $workerId => $worker) {
            $result = pcntl_waitpid($worker->pid, $status, WNOHANG);

            if ($result === $worker->pid) {
                error_log("[SharedSocketMaster] Worker $workerId (PID {$worker->pid}) died");

                unset($this->workers[$workerId]);

                if ($this->config->autoRestart && !$this->shouldStop) {
                    error_log("[SharedSocketMaster] Respawning worker $workerId...");
                    sleep($this->config->restartDelay);
                    $this->spawnWorker($workerId);
                }
            }
        }
    }

    private function waitForWorkers(): void
    {
        foreach ($this->workers as $worker) {
            pcntl_waitpid($worker->pid, $status);
        }
    }

    private function setupSignals(): void
    {
        $this->signalHandler->register(SIGTERM, function (): void {
            error_log("[SharedSocketMaster] Received SIGTERM");
            $this->stop();
        });

        $this->signalHandler->register(SIGINT, function (): void {
            error_log("[SharedSocketMaster] Received SIGINT");
            $this->stop();
        });
    }
}

