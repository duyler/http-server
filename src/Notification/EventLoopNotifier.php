<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Notification;

use Override;

final readonly class EventLoopNotifier implements EventLoopNotifierInterface
{
    /**
     * @param callable(): void $callback
     */
    public function __construct(
        private mixed $callback,
    ) {}

    #[Override]
    public function notify(): void
    {
        ($this->callback)();
    }
}
