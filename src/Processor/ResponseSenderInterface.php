<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends HTTP responses over connections
 */
interface ResponseSenderInterface
{
    public function send(ConnectionInterface $connection, ResponseInterface $response): void;

    public function sendError(ConnectionInterface $connection, int $status, string $message): void;
}
