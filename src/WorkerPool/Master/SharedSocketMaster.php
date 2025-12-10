<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Duyler\HttpServer\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use Fiber;
use InvalidArgumentException;
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
        private readonly ?WorkerCallbackInterface $workerCallback = null,
        private readonly ?EventDrivenWorkerInterface $eventDrivenWorker = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($config, $logger);

        // Validate: at least one interface must be provided
        if ($this->workerCallback === null && $this->eventDrivenWorker === null) {
            throw new InvalidArgumentException(
                'Either workerCallback or eventDrivenWorker must be provided',
            );
        }

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

            // Choose worker mode based on provided interface
            if ($this->eventDrivenWorker !== null) {
                $this->runEventDrivenWorker($workerId);
            } elseif ($this->workerCallback !== null) {
                $this->runCallbackWorker($workerId);
            }

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

    /**
     * Event-Driven Worker mode
     *
     * Runs a full application with its own event loop.
     * Master passes connections to Server, application polls hasRequest().
     */
    private function runEventDrivenWorker(int $workerId): void
    {
        assert($this->eventDrivenWorker !== null);

        // 1. Create Server (without start - no socket creation)
        $server = new Server($this->serverConfig);
        $server->setWorkerId($workerId);

        // 2. Create socket with SO_REUSEPORT
        $socket = $this->createSharedSocket($workerId);

        // 3. Start background Fiber to accept connections
        $fiber = $this->createConnectionAcceptorFiber($socket, $server, $workerId);
        $fiber->start();
        $server->registerFiber($fiber);

        // 4. Run application (NEVER returns - infinite loop inside)
        $this->logger->info('Starting event-driven worker', ['worker_id' => $workerId]);
        $this->eventDrivenWorker->run($workerId, $server);
    }

    /**
     * Creates shared socket with SO_REUSEPORT
     */
    private function createSharedSocket(int $workerId): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new WorkerPoolException('Failed to create socket');
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->logger->error('Failed to set SO_REUSEADDR', ['worker_id' => $workerId]);
            throw new WorkerPoolException('Failed to set SO_REUSEADDR');
        }

        if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1)) {
            $this->logger->error('Failed to set SO_REUSEPORT', ['worker_id' => $workerId]);
            throw new WorkerPoolException('Failed to set SO_REUSEPORT');
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
            throw new WorkerPoolException("Failed to bind socket: $error");
        }

        if (!socket_listen($socket, 128)) {
            $this->logger->error('Failed to listen', [
                'worker_id' => $workerId,
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
            throw new WorkerPoolException('Failed to listen');
        }

        // CRITICAL: Set listening socket to non-blocking mode!
        // This is essential for socket_accept() in Fiber to not block
        socket_set_nonblock($socket);

        $this->logger->info('Worker socket ready', [
            'worker_id' => $workerId,
            'host' => $host,
            'port' => $port,
        ]);

        socket_set_nonblock($socket);

        return $socket;
    }

    /**
     * Creates Fiber for background connection acceptance
     *
     * @return Fiber<mixed, mixed, mixed, void>
     */
    private function createConnectionAcceptorFiber(
        Socket $socket,
        Server $server,
        int $workerId,
    ): Fiber {
        return new Fiber(function () use ($socket, $server, $workerId): void {
            while (true) { // @phpstan-ignore while.alwaysTrue
                $clientSocket = socket_accept($socket);

                if ($clientSocket !== false) {
                    // CRITICAL: Set client socket to non-blocking mode!
                    // Without this, read operations will block the worker
                    socket_set_nonblock($clientSocket);

                    $clientIp = '';
                    socket_getpeername($clientSocket, $clientIp);

                    $this->logger->debug('Connection accepted in fiber', [
                        'worker_id' => $workerId,
                        'client_ip' => $clientIp,
                    ]);

                    // Add connection to Server queue
                    // Application will get it via hasRequest()
                    $server->addExternalConnection($clientSocket, [
                        'worker_id' => $workerId,
                        'client_ip' => $clientIp,
                    ]);
                }

                // Suspend and give control back to application
                Fiber::suspend();
            }
        });
    }

    /**
     * Callback Worker mode (legacy, for backward compatibility)
     *
     * Synchronous handling of each connection via callback.
     */
    private function runCallbackWorker(int $workerId): void
    {
        assert($this->workerCallback !== null);

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
