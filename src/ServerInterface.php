<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

interface ServerInterface
{
    public function start(): void;

    public function stop(): void;

    public function reset(): void;

    public function restart(): bool;

    public function hasRequest(): bool;

    public function getRequest(): ServerRequestInterface;

    public function respond(ResponseInterface $response): void;

    public function hasPendingResponse(): bool;

    public function setLogger(?LoggerInterface $logger): void;
}
