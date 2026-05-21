<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles WebSocket upgrade requests during HTTP processing
 */
interface WebSocketUpgradeHandlerInterface
{
    public function handleUpgrade(ConnectionInterface $connection, ServerRequestInterface $request): void;
}
