<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Notification;

/**
 * Notifies the event loop about pending requests or responses
 */
interface EventLoopNotifierInterface
{
    public function notify(): void;
}
