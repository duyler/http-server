<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Contract;

interface ServerLifecycleInterface
{
    public function start(): bool;

    public function stop(): void;

    public function reset(): void;

    public function restart(): bool;

    public function shutdown(int $timeout): bool;
}
