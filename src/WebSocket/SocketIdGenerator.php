<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\Socket\SocketResourceInterface;

final readonly class SocketIdGenerator
{
    public function generate(SocketResourceInterface $socket): int
    {
        return spl_object_id($socket);
    }
}
