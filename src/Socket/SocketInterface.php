<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

interface SocketInterface extends SocketResourceInterface
{
    public function bind(string $address, int $port): void;

    public function listen(int $backlog = 511): void;

    public function accept(): SocketResourceInterface|false;
}
