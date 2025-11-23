<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Socket;

use Duyler\HttpServer\Constants;

interface SocketInterface
{
    public function bind(string $address, int $port): void;

    public function listen(int $backlog = Constants::DEFAULT_LISTEN_BACKLOG): void;

    /**
     * @return resource|false
     */
    public function accept();

    public function setBlocking(bool $blocking): void;

    public function close(): void;

    /**
     * @return resource
     */
    public function getResource();

    public function isValid(): bool;
}
