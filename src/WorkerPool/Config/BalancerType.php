<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Config;

enum BalancerType: string
{
    case LeastConnections = "least_connections";
    case RoundRobin = "round_robin";
    case Weighted = "weighted";
}
