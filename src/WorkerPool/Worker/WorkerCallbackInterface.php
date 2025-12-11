<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Worker;

use Socket;

interface WorkerCallbackInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function handle(Socket $clientSocket, array $metadata): void;
}
