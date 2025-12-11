<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGUSR1, SIG_IGN);
    pcntl_signal(SIGUSR2, SIG_IGN);
    pcntl_async_signals(true);
}
