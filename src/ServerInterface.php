<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface ServerInterface
{
    public function start(): bool;

    public function stop(): void;

    public function reset(): void;

    public function restart(): bool;

    public function hasRequest(): bool;

    public function getRequest(): ?ServerRequestInterface;

    public function respond(ResponseInterface $response): void;

    public function hasPendingResponse(): bool;

    public function shutdown(int $timeout): bool;

    public function setLogger(?LoggerInterface $logger): void;

    /**
     * @return array<string, int|float|string>
     */
    public function getMetrics(): array;
}
