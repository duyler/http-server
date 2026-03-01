<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;

interface RequestProcessorInterface
{
    public function processRequest(ConnectionInterface $connection): void;

    public function sendResponse(ConnectionInterface $connection, ResponseInterface $response): void;

    public function sendErrorResponse(ConnectionInterface $connection, int $statusCode, string $message): void;

    public function generateRequestId(): string;
}
