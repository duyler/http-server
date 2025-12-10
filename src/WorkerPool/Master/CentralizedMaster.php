<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Master;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Duyler\HttpServer\WorkerPool\Balancer\BalancerInterface;
use Duyler\HttpServer\WorkerPool\Config\WorkerPoolConfig;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Duyler\HttpServer\WorkerPool\IPC\FdPasser;
use Duyler\HttpServer\WorkerPool\Process\ProcessInfo;
use Duyler\HttpServer\WorkerPool\Process\ProcessState;
use Duyler\HttpServer\WorkerPool\Worker\EventDrivenWorkerInterface;
use Duyler\HttpServer\WorkerPool\Worker\WorkerCallbackInterface;
use Fiber;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Socket;

/**
 * Centralized Master with custom load balancing
 *
 * Architecture:
 * - Single master process accepts all connections
 * - Master maintains centralized queue
 * - Master distributes connections to workers via IPC (FD passing)
 * - Custom load balancing algorithms (Least Connections, Round Robin)
 *
 * Requirements:
 * - Linux (SCM_RIGHTS support)
 * - socket_sendmsg/socket_recvmsg functions
 *
 * Use when:
 * - Need custom load balancing
 * - Need sticky sessions
 * - Need centralized connection queue
 * - Running on Linux
 *
 * @see SharedSocketMaster For distributed architecture with kernel load balancing
 */
class CentralizedMaster extends AbstractMaster
{
    /**
     * @var array<int, Socket>
     */
    private array $workerSockets = [];

    private ?SocketManager $socketManager = null;
    private ?ConnectionQueue $connectionQueue = null;
    private FdPasser $fdPasser;
    private WorkerManager $workerManager;
    private ConnectionRouter $connectionRouter;

    public function __construct(
        WorkerPoolConfig $config,
        private readonly BalancerInterface $balancer,
        private readonly ?ServerConfig $serverConfig = null,
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

        $this->fdPasser = new FdPasser($this->logger);
        $this->workerManager = new WorkerManager($this->logger);
        $this->connectionRouter = new ConnectionRouter($this->balancer, $this->logger);

        if ($this->serverConfig !== null) {
            $this->socketManager = new SocketManager($this->serverConfig, $this->logger);
            $this->connectionQueue = new ConnectionQueue(maxSize: 1000);
        }
    }

    public function start(): void
    {
        if ($this->socketManager !== null) {
            $this->logger->info('Starting socket manager');
            $this->socketManager->listen();
            $this->logger->info('Socket manager listening on port');
        } else {
            $this->logger->warning('No socket manager configured');
        }

        $this->logger->info('Spawning workers', ['count' => $this->config->workerCount]);
        for ($i = 1; $i <= $this->config->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        $this->logger->info('Entering main loop');
        $this->run();
    }

    public function stop(): void
    {
        parent::stop();
        $this->workerManager->stopAll();
    }

    protected function run(): void
    {
        $iteration = 0;

        while (!$this->shouldStop) {
            $this->signalHandler->dispatch();

            if ($this->socketManager !== null && $this->connectionQueue !== null) {
                $socket = $this->socketManager->getSocket();

                if ($socket !== null) {
                    $readSockets = [$socket];
                    $write = null;
                    $except = null;
                    $timeout = 0;
                    $microseconds = $this->config->pollInterval;

                    $changed = socket_select($readSockets, $write, $except, $timeout, $microseconds);

                    if ($changed === false) {
                        $errorCode = socket_last_error();
                        if ($errorCode !== SOCKET_EINTR) {
                            $this->logger->error('socket_select failed', [
                                'error' => socket_strerror($errorCode),
                                'error_code' => $errorCode,
                            ]);
                        }
                    } elseif ($changed > 0) {
                        $this->acceptConnections();
                    }
                }

                $this->distributeConnections();
            } else {
                if ($iteration === 0) {
                    $this->logger->error('No socket manager in main loop');
                }
                usleep($this->config->pollInterval);
            }

            $this->checkWorkers();

            $iteration++;
            if ($iteration % 1000 === 0) {
                $this->logger->debug('Main loop iteration', [
                    'iteration' => $iteration,
                    'workers_alive' => count($this->workers),
                ]);
            }
        }

        $this->logger->info('Exiting main loop, waiting for workers');
        $this->waitForWorkers();
    }

    private function acceptConnections(): void
    {
        static $callCount = 0;
        $callCount++;

        if ($callCount % 1000 === 0) {
            $this->logger->debug('Accept connections called', ['count' => $callCount]);
        }

        if ($this->socketManager === null || $this->connectionQueue === null) {
            if ($callCount === 1) {
                $this->logger->error('Socket manager or connection queue is null');
            }
            return;
        }

        for ($i = 0; $i < 10; $i++) {
            $clientSocket = $this->socketManager->accept();

            if ($clientSocket === null) {
                break;
            }

            $this->logger->info('Accepted new connection');

            if ($this->connectionQueue->isFull()) {
                $this->logger->warning('Queue full, rejecting connection');
                socket_close($clientSocket);
                break;
            }

            $this->connectionQueue->enqueue($clientSocket);
            $this->logger->debug('Connection queued', ['queue_size' => $this->connectionQueue->size()]);
        }
    }

    private function distributeConnections(): void
    {
        if ($this->connectionQueue === null) {
            return;
        }

        while (!$this->connectionQueue->isEmpty()) {
            $clientSocket = $this->connectionQueue->dequeue();

            if ($clientSocket === null) {
                break;
            }

            $this->connectionRouter->route(
                clientSocket: $clientSocket,
                workers: $this->workers,
                workerSockets: $this->workerSockets,
            );
        }
    }

    protected function spawnWorker(int $workerId): void
    {
        $sockets = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);

        if ($result === false || count($sockets) !== 2) {
            throw new WorkerPoolException("Failed to create socket pair for worker $workerId");
        }

        [$masterSocket, $workerSocket] = $sockets;

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new WorkerPoolException("Failed to fork worker $workerId");
        }

        if ($pid === 0) {
            socket_close($masterSocket);

            if ($this->socketManager !== null) {
                $this->socketManager->detachFromWorker();
            }

            $this->logger->info('Worker process started', [
                'worker_id' => $workerId,
                'pid' => getmypid(),
            ]);

            // Choose worker mode based on provided interface
            if ($this->eventDrivenWorker !== null) {
                $this->runEventDrivenWorker($workerId, $workerSocket);
            } elseif ($this->workerCallback !== null) {
                $this->runCallbackWorker($workerId, $workerSocket);
            }

            $this->logger->info('Worker process exiting', ['worker_id' => $workerId]);
            exit(0);
        }

        socket_close($workerSocket);

        $this->workers[$workerId] = new ProcessInfo(
            workerId: $workerId,
            pid: $pid,
            state: ProcessState::Ready,
        );

        $this->workerSockets[$workerId] = $masterSocket;
        $this->logger->info('Worker spawned', ['worker_id' => $workerId, 'pid' => $pid]);
    }

    /**
     * Event-Driven Worker mode with FD Passing
     *
     * Runs a full application with its own event loop.
     * Master passes FDs via IPC, application polls hasRequest().
     */
    private function runEventDrivenWorker(int $workerId, Socket $workerSocket): void
    {
        assert($this->eventDrivenWorker !== null);

        // 1. Create Server
        $server = new Server($this->serverConfig ?? new ServerConfig());
        $server->setWorkerId($workerId);

        // 2. Start background Fiber to receive FDs from master
        $fiber = new Fiber(function () use ($workerSocket, $server, $workerId): void {
            while (true) { // @phpstan-ignore while.alwaysTrue
                $result = $this->fdPasser->receiveFd($workerSocket);

                if ($result !== null) {
                    $this->logger->debug('Worker received FD from master', [
                        'worker_id' => $workerId,
                    ]);

                    $clientSocket = $result['fd'];
                    /** @var array{client_ip?: string, worker_id: int, worker_pid?: int} $metadata */
                    $metadata = $result['metadata'];

                    // CRITICAL: Set client socket to non-blocking mode!
                    // Without this, read operations will block the worker
                    socket_set_nonblock($clientSocket);

                    // Add to Server queue for hasRequest()
                    $server->addExternalConnection($clientSocket, $metadata);
                }

                // Suspend and give control back to application
                Fiber::suspend();
            }
        });

        $fiber->start();
        $server->registerFiber($fiber);

        // 3. Run application (NEVER returns)
        $this->logger->info('Starting event-driven worker', ['worker_id' => $workerId]);
        $this->eventDrivenWorker->run($workerId, $server);
    }

    /**
     * Callback Worker mode (legacy, for backward compatibility)
     *
     * Synchronous handling via callback for each received FD.
     */
    private function runCallbackWorker(int $workerId, Socket $workerSocket): void
    {
        $running = true;
        $this->logger->info('Worker entering receive loop', ['worker_id' => $workerId]);

        /** @phpstan-ignore-next-line */
        while ($running) {
            $result = $this->fdPasser->receiveFd($workerSocket);

            if ($result === null) {
                usleep(1000);
                continue;
            }

            $this->logger->debug('Worker received FD from master', ['worker_id' => $workerId]);

            $clientSocket = $result['fd'];
            $metadata = $result['metadata'];

            if ($this->workerCallback !== null) {
                $this->workerCallback->handle($clientSocket, $metadata);
            } else {
                $this->logger->warning('Worker has no callback, closing socket', ['worker_id' => $workerId]);
                socket_close($clientSocket);
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
            'queue_size' => $this->connectionQueue?->size() ?? 0,
            'is_running' => $this->isRunning(),
        ];
    }

    public function getBalancer(): BalancerInterface
    {
        return $this->balancer;
    }
}
