<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Security;

use Override;
use Psr\Log\LoggerInterface;

final readonly class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    #[Override]
    public function logSecurityEvent(string $eventType, array $context = []): void
    {
        $this->logger->warning("Security event: {$eventType}", [
            'event_type' => $eventType,
            'timestamp' => time(),
            'ip' => $context['ip'] ?? 'unknown',
            'user_agent' => $context['user_agent'] ?? 'unknown',
            'request_id' => $context['request_id'] ?? null,
            ...$context,
        ]);
    }

    #[Override]
    public function logRateLimitExceeded(string $ip, int $requestCount): void
    {
        $this->logSecurityEvent('rate_limit_exceeded', [
            'ip' => $ip,
            'request_count' => $requestCount,
        ]);
    }

    #[Override]
    public function logMaxIdentifiersReached(int $current, int $limit): void
    {
        $this->logSecurityEvent('max_identifiers_reached', [
            'current_identifiers' => $current,
            'max_identifiers' => $limit,
        ]);
    }

    #[Override]
    public function logWebSocketConnection(string $ip, string $origin, bool $accepted): void
    {
        $this->logSecurityEvent('websocket_connection', [
            'ip' => $ip,
            'origin' => $origin,
            'accepted' => $accepted,
        ]);
    }

    #[Override]
    public function logPathTraversalAttempt(string $ip, string $path): void
    {
        $this->logSecurityEvent('path_traversal_attempt', [
            'ip' => $ip,
            'path' => $path,
        ]);
    }

    #[Override]
    public function logInvalidOrigin(string $ip, string $origin): void
    {
        $this->logSecurityEvent('invalid_origin', [
            'ip' => $ip,
            'origin' => $origin,
        ]);
    }

    #[Override]
    public function logMemoryLimitExceeded(int $usage, int $limit): void
    {
        $this->logSecurityEvent('memory_limit_exceeded', [
            'memory_usage' => $usage,
            'memory_limit' => $limit,
        ]);
    }

    #[Override]
    public function logMaxConnectionsReached(int $current, int $limit): void
    {
        $this->logSecurityEvent('max_connections_reached', [
            'current_connections' => $current,
            'max_connections' => $limit,
        ]);
    }
}
