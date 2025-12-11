<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketConfigException;

readonly class WebSocketConfig
{
    /**
     * @var array<string> $allowedOrigins
     */
    public readonly array $allowedOrigins;

    /**
     * @var array<string> $subProtocols
     */
    public readonly array $subProtocols;

    /**
     * @param array<mixed> $allowedOrigins
     * @param array<mixed> $subProtocols
     */
    public function __construct(
        public int $maxMessageSize = 1048576,
        public int $maxFrameSize = 65536,
        public int $pingInterval = 30,
        public int $pongTimeout = 10,
        public bool $autoPing = true,
        public int $handshakeTimeout = 5,
        public int $closeTimeout = 5,
        array $allowedOrigins = ['*'],
        public bool $validateOrigin = false,
        public bool $requireMasking = true,
        public bool $autoFragmentation = true,
        public int $writeBufferSize = 8192,
        public bool $enableCompression = false,
        array $subProtocols = [],
    ) {
        $this->validate(
            $this->maxMessageSize,
            $this->maxFrameSize,
            $this->pingInterval,
            $this->pongTimeout,
            $this->handshakeTimeout,
            $this->closeTimeout,
            $this->writeBufferSize,
            $allowedOrigins,
            $subProtocols,
        );

        /** @var array<string> $allowedOrigins */
        $this->allowedOrigins = $allowedOrigins;
        /** @var array<string> $subProtocols */
        $this->subProtocols = $subProtocols;
    }

    /**
     * @param array<mixed> $allowedOrigins
     * @param array<mixed> $subProtocols
     */
    private function validate(
        int $maxMessageSize,
        int $maxFrameSize,
        int $pingInterval,
        int $pongTimeout,
        int $handshakeTimeout,
        int $closeTimeout,
        int $writeBufferSize,
        array $allowedOrigins,
        array $subProtocols,
    ): void {
        if ($maxMessageSize < 1) {
            throw new InvalidWebSocketConfigException('maxMessageSize must be positive');
        }

        if ($maxFrameSize < 1) {
            throw new InvalidWebSocketConfigException('maxFrameSize must be positive');
        }

        if ($maxFrameSize > $maxMessageSize) {
            throw new InvalidWebSocketConfigException('maxFrameSize cannot exceed maxMessageSize');
        }

        if ($pingInterval < 1) {
            throw new InvalidWebSocketConfigException('pingInterval must be positive');
        }

        if ($pongTimeout < 1) {
            throw new InvalidWebSocketConfigException('pongTimeout must be positive');
        }

        if ($handshakeTimeout < 1) {
            throw new InvalidWebSocketConfigException('handshakeTimeout must be positive');
        }

        if ($closeTimeout < 1) {
            throw new InvalidWebSocketConfigException('closeTimeout must be positive');
        }

        if ($writeBufferSize < 1) {
            throw new InvalidWebSocketConfigException('writeBufferSize must be positive');
        }

        if ($allowedOrigins === []) {
            throw new InvalidWebSocketConfigException('allowedOrigins cannot be empty');
        }

        foreach ($allowedOrigins as $origin) {
            if (!is_string($origin)) {
                throw new InvalidWebSocketConfigException('allowedOrigins must contain only strings');
            }
        }

        foreach ($subProtocols as $protocol) {
            if (!is_string($protocol)) {
                throw new InvalidWebSocketConfigException('subProtocols must contain only strings');
            }
        }
    }
}
