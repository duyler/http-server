<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\WebSocket\Exception\InvalidWebSocketConfigException;

readonly class WebSocketConfig
{
    /**
     * @param array<string> $allowedOrigins
     * @param array<string> $subProtocols
     */
    public function __construct(
        public int $maxMessageSize = 1048576,
        public int $maxFrameSize = 65536,
        public int $pingInterval = 30,
        public int $pongTimeout = 10,
        public bool $autoPing = true,
        public int $handshakeTimeout = 5,
        public int $closeTimeout = 5,
        public array $allowedOrigins = ['*'],
        public bool $validateOrigin = false,
        public bool $requireMasking = true,
        public bool $autoFragmentation = true,
        public int $writeBufferSize = 8192,
        public bool $enableCompression = false,
        public array $subProtocols = [],
    ) {
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->maxMessageSize < 1) {
            throw new InvalidWebSocketConfigException('maxMessageSize must be positive');
        }

        if ($this->maxFrameSize < 1) {
            throw new InvalidWebSocketConfigException('maxFrameSize must be positive');
        }

        if ($this->maxFrameSize > $this->maxMessageSize) {
            throw new InvalidWebSocketConfigException('maxFrameSize cannot exceed maxMessageSize');
        }

        if ($this->pingInterval < 1) {
            throw new InvalidWebSocketConfigException('pingInterval must be positive');
        }

        if ($this->pongTimeout < 1) {
            throw new InvalidWebSocketConfigException('pongTimeout must be positive');
        }

        if ($this->handshakeTimeout < 1) {
            throw new InvalidWebSocketConfigException('handshakeTimeout must be positive');
        }

        if ($this->closeTimeout < 1) {
            throw new InvalidWebSocketConfigException('closeTimeout must be positive');
        }

        if ($this->writeBufferSize < 1) {
            throw new InvalidWebSocketConfigException('writeBufferSize must be positive');
        }

        if ($this->allowedOrigins === []) {
            throw new InvalidWebSocketConfigException('allowedOrigins cannot be empty');
        }
    }
}
