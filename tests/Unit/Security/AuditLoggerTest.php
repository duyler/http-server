<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Security;

use Duyler\HttpServer\Security\AuditLogger;
use Duyler\HttpServer\Security\AuditLoggerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerTest extends TestCase
{
    #[Test]
    public function log_security_event_logs_with_correct_format(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: rate_limit_exceeded',
                $this->callback(fn(array $context): bool => isset($context['event_type'])
                    && 'rate_limit_exceeded' === $context['event_type']
                    && isset($context['ip'])
                    && isset($context['timestamp'])),
            );

        $auditLogger->logRateLimitExceeded('192.168.1.1', 100);
    }

    #[Test]
    public function log_rate_limit_exceeded_includes_request_count(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: rate_limit_exceeded',
                $this->callback(fn(array $context): bool => isset($context['request_count'])
                    && 150 === $context['request_count']
                    && '192.168.1.50' === $context['ip']),
            );

        $auditLogger->logRateLimitExceeded('192.168.1.50', 150);
    }

    #[Test]
    public function log_web_socket_connection_logs_accepted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: websocket_connection',
                $this->callback(fn(array $context): bool => 'websocket_connection' === $context['event_type']
                    && 'https://example.com' === $context['origin']
                    && true === $context['accepted']
                    && '10.0.0.1' === $context['ip']),
            );

        $auditLogger->logWebSocketConnection('10.0.0.1', 'https://example.com', true);
    }

    #[Test]
    public function log_web_socket_connection_logs_rejected(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: websocket_connection',
                $this->callback(fn(array $context): bool => 'websocket_connection' === $context['event_type']
                    && 'https://evil.com' === $context['origin']
                    && false === $context['accepted']),
            );

        $auditLogger->logWebSocketConnection('10.0.0.1', 'https://evil.com', false);
    }

    #[Test]
    public function log_path_traversal_attempt_includes_path(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: path_traversal_attempt',
                $this->callback(fn(array $context): bool => 'path_traversal_attempt' === $context['event_type']
                    && '/../../../etc/passwd' === $context['path']
                    && '192.168.1.100' === $context['ip']),
            );

        $auditLogger->logPathTraversalAttempt('192.168.1.100', '/../../../etc/passwd');
    }

    #[Test]
    public function log_invalid_origin_includes_origin(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: invalid_origin',
                $this->callback(fn(array $context): bool => 'invalid_origin' === $context['event_type']
                    && 'https://malicious.com' === $context['origin']
                    && '172.16.0.1' === $context['ip']),
            );

        $auditLogger->logInvalidOrigin('172.16.0.1', 'https://malicious.com');
    }

    #[Test]
    public function log_memory_limit_exceeded_includes_memory_info(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: memory_limit_exceeded',
                $this->callback(fn(array $context): bool => 'memory_limit_exceeded' === $context['event_type']
                    && 134217728 === $context['memory_usage']
                    && 67108864 === $context['memory_limit']),
            );

        $auditLogger->logMemoryLimitExceeded(134217728, 67108864);
    }

    #[Test]
    public function log_max_connections_reached_includes_connection_info(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: max_connections_reached',
                $this->callback(fn(array $context): bool => 'max_connections_reached' === $context['event_type']
                    && 1000 === $context['current_connections']
                    && 1000 === $context['max_connections']),
            );

        $auditLogger->logMaxConnectionsReached(1000, 1000);
    }

    #[Test]
    public function log_max_identifiers_reached_includes_identifier_info(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: max_identifiers_reached',
                $this->callback(fn(array $context): bool => 'max_identifiers_reached' === $context['event_type']
                    && 5000 === $context['current_identifiers']
                    && 5000 === $context['max_identifiers']),
            );

        $auditLogger->logMaxIdentifiersReached(5000, 5000);
    }

    #[Test]
    public function log_security_event_includes_default_values(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: custom_event',
                $this->callback(fn(array $context): bool => 'custom_event' === $context['event_type']
                    && 'unknown' === $context['ip']
                    && 'unknown' === $context['user_agent']
                    && null === $context['request_id']),
            );

        $auditLogger->logSecurityEvent('custom_event');
    }

    #[Test]
    public function log_security_event_merges_custom_context(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: custom_event',
                $this->callback(fn(array $context): bool => 'custom_event' === $context['event_type']
                    && '10.20.30.40' === $context['ip']
                    && 'CustomAgent/1.0' === $context['user_agent']
                    && 'req-12345' === $context['request_id']
                    && 'extra_value' === $context['extra_key']),
            );

        $auditLogger->logSecurityEvent('custom_event', [
            'ip' => '10.20.30.40',
            'user_agent' => 'CustomAgent/1.0',
            'request_id' => 'req-12345',
            'extra_key' => 'extra_value',
        ]);
    }

    #[Test]
    public function implements_audit_logger_interface(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $this->assertInstanceOf(AuditLoggerInterface::class, $auditLogger);
    }

    #[Test]
    public function timestamp_is_unix_timestamp(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $auditLogger = new AuditLogger($logger);

        $beforeTime = time();

        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Security event: test',
                $this->callback(function (array $context) use ($beforeTime): bool {
                    $afterTime = time();
                    return $context['timestamp'] >= $beforeTime
                        && $context['timestamp'] <= $afterTime;
                }),
            );

        $auditLogger->logSecurityEvent('test');
    }
}
