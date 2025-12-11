<?php

declare(strict_types=1);

namespace Duyler\HttpServer;

final class Constants
{
    public const int MIN_PORT = 1;
    public const int MAX_PORT = 65535;

    public const int DEFAULT_LISTEN_BACKLOG = 511;

    public const int SHUTDOWN_POLL_INTERVAL_MICROSECONDS = 100000;

    public const int MILLISECONDS_PER_SECOND = 1000;

    public const int PERCENT_MULTIPLIER = 100;

    private function __construct() {}
}
