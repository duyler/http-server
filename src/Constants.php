<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

final class Constants
{
    public const MIN_PORT = 1;
    public const MAX_PORT = 65535;

    public const DEFAULT_LISTEN_BACKLOG = 511;

    public const SHUTDOWN_POLL_INTERVAL_MICROSECONDS = 100000;

    public const MILLISECONDS_PER_SECOND = 1000;

    public const PERCENT_MULTIPLIER = 100;

    private function __construct() {}
}
