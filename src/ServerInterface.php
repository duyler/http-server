<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Duyler\HttpServer\Contract\MetricsInterface;
use Duyler\HttpServer\Contract\RequestLifecycleInterface;
use Duyler\HttpServer\Contract\ServerLifecycleInterface;
use Duyler\HttpServer\Contract\WorkerPoolIntegrationInterface;
use Duyler\HttpServer\WebSocket\WebSocketServer;
use Psr\Log\LoggerInterface;

interface ServerInterface extends
    RequestLifecycleInterface,
    ServerLifecycleInterface,
    WorkerPoolIntegrationInterface,
    MetricsInterface
{
    public function setLogger(LoggerInterface $logger): void;

    public function attachWebSocket(string $path, WebSocketServer $ws): void;
}
