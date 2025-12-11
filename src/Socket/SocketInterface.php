<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Constants;

interface SocketInterface extends SocketResourceInterface
{
    public function bind(string $address, int $port): void;

    public function listen(int $backlog = Constants::DEFAULT_LISTEN_BACKLOG): void;

    public function accept(): SocketResourceInterface|false;
}
