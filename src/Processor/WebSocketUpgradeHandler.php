<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Connection\ConnectionInterface;
use Override;
use Psr\Http\Message\ServerRequestInterface;

final readonly class WebSocketUpgradeHandler implements WebSocketUpgradeHandlerInterface
{
    /**
     * @param callable(ConnectionInterface, ServerRequestInterface): void $handler
     */
    public function __construct(
        private mixed $handler,
    ) {}

    #[Override]
    public function handleUpgrade(ConnectionInterface $connection, ServerRequestInterface $request): void
    {
        ($this->handler)($connection, $request);
    }
}
