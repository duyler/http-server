<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Config;

use Duyler\HttpServer\Constants;
use Duyler\HttpServer\Exception\InvalidConfigException;

final readonly class ServerConfig
{
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public bool $ssl = false,
        public ?string $sslCert = null,
        public ?string $sslKey = null,
        public ?string $publicPath = null,
        public int $requestTimeout = 30,
        public int $connectionTimeout = 60,
        public int $maxConnections = 1000,
        public int $maxRequestSize = 10485760,
        public int $bufferSize = 8192,
        public bool $enableKeepAlive = true,
        public int $keepAliveTimeout = 30,
        public int $keepAliveMaxRequests = 100,
        public bool $enableStaticCache = true,
        public int $staticCacheSize = 52428800,
        public bool $enableRateLimit = false,
        public int $rateLimitRequests = 100,
        public int $rateLimitWindow = 60,
        public int $maxAcceptsPerCycle = 10,
        public int $socketBacklog = 511,
        public int $headerCacheLimit = 100,
        public bool $debugMode = false,
        public int $memoryLimit = 134217728,
        public bool $enableSecurityHeaders = true,
        public string $frameOptions = 'DENY',
        public string $referrerPolicy = 'strict-origin-when-cross-origin',
        public string $permissionsPolicy = 'geolocation=(), microphone=(), camera=()',
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->port < Constants::MIN_PORT || $this->port > Constants::MAX_PORT) {
            throw new InvalidConfigException(sprintf(
                'Port must be between %d and %d',
                Constants::MIN_PORT,
                Constants::MAX_PORT,
            ));
        }

        if ($this->requestTimeout < 1) {
            throw new InvalidConfigException('Request timeout must be positive');
        }

        if ($this->connectionTimeout < 1) {
            throw new InvalidConfigException('Connection timeout must be positive');
        }

        if ($this->maxConnections < 1) {
            throw new InvalidConfigException('Max connections must be positive');
        }

        if ($this->maxRequestSize < 1024) {
            throw new InvalidConfigException('Max request size must be at least 1024 bytes');
        }

        if ($this->bufferSize < 1024) {
            throw new InvalidConfigException('Buffer size must be at least 1024 bytes');
        }

        if ($this->ssl) {
            if (null === $this->sslCert || '' === $this->sslCert) {
                throw new InvalidConfigException('SSL certificate path is required when SSL is enabled');
            }

            if (null === $this->sslKey || '' === $this->sslKey) {
                throw new InvalidConfigException('SSL key path is required when SSL is enabled');
            }

            if (false === file_exists($this->sslCert)) {
                throw new InvalidConfigException(sprintf('SSL certificate file not found: %s', $this->sslCert));
            }

            if (false === file_exists($this->sslKey)) {
                throw new InvalidConfigException(sprintf('SSL key file not found: %s', $this->sslKey));
            }
        }

        if (null !== $this->publicPath && false === is_dir($this->publicPath)) {
            throw new InvalidConfigException(sprintf('Public path is not a directory: %s', $this->publicPath));
        }

        if ($this->keepAliveTimeout < 1) {
            throw new InvalidConfigException('Keep-alive timeout must be positive');
        }

        if ($this->keepAliveMaxRequests < 1) {
            throw new InvalidConfigException('Keep-alive max requests must be positive');
        }

        if ($this->staticCacheSize < 0) {
            throw new InvalidConfigException('Static cache size must be non-negative');
        }

        if ($this->rateLimitRequests < 1) {
            throw new InvalidConfigException('Rate limit requests must be positive');
        }

        if ($this->rateLimitWindow < 1) {
            throw new InvalidConfigException('Rate limit window must be positive');
        }

        if ($this->maxAcceptsPerCycle < 1) {
            throw new InvalidConfigException('Max accepts per cycle must be positive');
        }

        if ($this->socketBacklog < 1) {
            throw new InvalidConfigException('Socket backlog must be positive');
        }

        if ($this->headerCacheLimit < 1) {
            throw new InvalidConfigException('Header cache limit must be positive');
        }

        if ($this->memoryLimit < 1048576) {
            throw new InvalidConfigException('Memory limit must be at least 1MB');
        }

        $validFrameOptions = ['DENY', 'SAMEORIGIN'];
        if (false === in_array($this->frameOptions, $validFrameOptions, true)) {
            throw new InvalidConfigException(sprintf(
                'Frame options must be one of: %s',
                implode(', ', $validFrameOptions),
            ));
        }

        $validReferrerPolicies = [
            'no-referrer',
            'no-referrer-when-downgrade',
            'origin',
            'origin-when-cross-origin',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
            'unsafe-url',
        ];
        if (false === in_array($this->referrerPolicy, $validReferrerPolicies, true)) {
            throw new InvalidConfigException(sprintf(
                'Referrer policy must be one of: %s',
                implode(', ', $validReferrerPolicies),
            ));
        }
    }

}
