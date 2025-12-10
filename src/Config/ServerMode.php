<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Config;

enum ServerMode: string
{
    case Standalone = 'standalone';
    case WorkerPool = 'worker_pool';
}

