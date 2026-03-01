<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\Connection\Connection as TcpConnection;
use Psr\Http\Message\ServerRequestInterface;

interface WebSocketHandlerInterface
{
    public function attachWebSocketServer(string $path, WebSocketServer $server): void;

    public function hasWebSocketServers(): bool;

    public function handleHandshake(TcpConnection $connection, ServerRequestInterface $request): bool;

    public function handleData(TcpConnection $connection): bool;

    public function processKeepalive(): void;

    public function closeAll(): void;

    public function reset(): void;
}
