<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Security;

interface AuditLoggerInterface
{
    public function logSecurityEvent(string $eventType, array $context = []): void;

    public function logRateLimitExceeded(string $ip, int $requestCount): void;

    public function logMaxIdentifiersReached(int $current, int $limit): void;

    public function logWebSocketConnection(string $ip, string $origin, bool $accepted): void;

    public function logPathTraversalAttempt(string $ip, string $path): void;

    public function logInvalidOrigin(string $ip, string $origin): void;

    public function logMemoryLimitExceeded(int $usage, int $limit): void;

    public function logMaxConnectionsReached(int $current, int $limit): void;
}
