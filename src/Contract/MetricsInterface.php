<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Contract;

interface MetricsInterface
{
    /**
     * @return array<string, int|float|string>
     */
    public function getMetrics(): array;
}
