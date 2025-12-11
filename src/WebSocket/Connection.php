<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\Connection\Connection as TcpConnection;
use Duyler\HttpServer\WebSocket\Enum\CloseCode;
use Duyler\HttpServer\WebSocket\Enum\ConnectionState;
use Duyler\HttpServer\WebSocket\Enum\Opcode;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class Connection
{
    private readonly string $id;
    private ConnectionState $state = ConnectionState::CONNECTING;

    /**
     * @var array<int, string>
     */
    private array $fragmentBuffer = [];
    private ?Opcode $fragmentOpcode = null;

    /**
     * @var array<string, mixed>
     */
    private array $userData = [];

    /**
     * @var array<string>
     */
    private array $rooms = [];

    private float $lastPong;
    private ?float $lastPing = null;

    public function __construct(
        private readonly TcpConnection $tcpConnection,
        private readonly ServerRequestInterface $upgradeRequest,
        private readonly WebSocketServer $server,
    ) {
        $this->id = uniqid('ws_', true);
        $this->lastPong = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getState(): ConnectionState
    {
        return $this->state;
    }

    public function setState(ConnectionState $state): void
    {
        $this->state = $state;
    }

    public function isOpen(): bool
    {
        return $this->state === ConnectionState::OPEN;
    }

    /**
     * @param array<mixed>|string $data
     */
    public function send(string|array $data, bool $binary = false): bool
    {
        if (!$this->isOpen()) {
            return false;
        }

        if (is_array($data)) {
            $encoded = json_encode($data);
            if ($encoded === false) {
                return false;
            }
            $data = $encoded;
        }

        $opcode = $binary ? Opcode::BINARY : Opcode::TEXT;
        $frame = new Frame($opcode, $data, fin: true, masked: false);

        return $this->sendFrame($frame);
    }

    public function sendFrame(Frame $frame): bool
    {
        try {
            $encoded = $frame->encode();
            $this->tcpConnection->write($encoded);
            return true;
        } catch (Throwable $e) {
            $this->server->handleConnectionError($this, $e);
            return false;
        }
    }

    public function ping(string $data = ''): bool
    {
        $frame = new Frame(Opcode::PING, $data, fin: true, masked: false);
        $this->lastPing = microtime(true);
        return $this->sendFrame($frame);
    }

    public function pong(string $data = ''): bool
    {
        $frame = new Frame(Opcode::PONG, $data, fin: true, masked: false);
        return $this->sendFrame($frame);
    }

    public function close(int $code = CloseCode::NORMAL->value, string $reason = ''): void
    {
        if ($this->state === ConnectionState::CLOSED) {
            return;
        }

        $payload = pack('n', $code) . $reason;
        $frame = new Frame(Opcode::CLOSE, $payload, fin: true, masked: false);

        $this->sendFrame($frame);
        $this->state = ConnectionState::CLOSING;
    }

    public function processFrame(Frame $frame): ?Message
    {
        return match ($frame->opcode) {
            Opcode::TEXT, Opcode::BINARY => $this->handleDataFrame($frame),
            Opcode::CONTINUATION => $this->handleContinuationFrame($frame),
            Opcode::CLOSE => $this->handleCloseFrame($frame),
            Opcode::PING => $this->handlePingFrame($frame),
            Opcode::PONG => $this->handlePongFrame($frame),
        };
    }

    private function handleDataFrame(Frame $frame): ?Message
    {
        if (!$frame->fin) {
            $this->fragmentOpcode = $frame->opcode;
            $this->fragmentBuffer[] = $frame->payload;
            return null;
        }

        return new Message($frame->payload, $frame->opcode);
    }

    private function handleContinuationFrame(Frame $frame): ?Message
    {
        $this->fragmentBuffer[] = $frame->payload;

        if ($frame->fin && $this->fragmentOpcode !== null) {
            $completePayload = implode('', $this->fragmentBuffer);
            $message = new Message($completePayload, $this->fragmentOpcode);

            $this->fragmentBuffer = [];
            $this->fragmentOpcode = null;

            return $message;
        }

        return null;
    }

    private function handleCloseFrame(Frame $frame): null
    {
        if ($this->state === ConnectionState::OPEN) {
            $this->sendFrame($frame);
        }

        $this->state = ConnectionState::CLOSED;

        $code = CloseCode::NORMAL->value;
        $reason = '';

        if (strlen($frame->payload) >= 2) {
            $unpacked = unpack('n', substr($frame->payload, 0, 2));
            if ($unpacked !== false) {
                $code = $unpacked[1];
                $reason = substr($frame->payload, 2);
            }
        }

        $this->server->emit('close', $this, $code, $reason);

        return null;
    }

    private function handlePingFrame(Frame $frame): null
    {
        $this->pong($frame->payload);
        return null;
    }

    private function handlePongFrame(Frame $frame): null
    {
        $this->lastPong = microtime(true);
        return null;
    }

    /**
     * @param array<mixed>|string $data
     */
    public function broadcast(string|array $data, bool $excludeSelf = false): void
    {
        $this->server->broadcast($data, $excludeSelf ? $this : null);
    }

    /**
     * @param array<mixed>|string $data
     */
    public function sendToRoom(string $room, string|array $data, bool $excludeSelf = false): void
    {
        $this->server->broadcastToRoom($room, $data, $excludeSelf ? $this : null);
    }

    public function setData(string $key, mixed $value): void
    {
        $this->userData[$key] = $value;
    }

    /**
     * @return mixed
     */
    public function getData(string $key, mixed $default = null): mixed
    {
        return $this->userData[$key] ?? $default;
    }

    public function hasData(string $key): bool
    {
        return isset($this->userData[$key]);
    }

    public function joinRoom(string $room): void
    {
        if (!in_array($room, $this->rooms, true)) {
            $this->rooms[] = $room;
            $this->server->addConnectionToRoom($this, $room);
        }
    }

    public function leaveRoom(string $room): void
    {
        $key = array_search($room, $this->rooms, true);
        if ($key !== false) {
            unset($this->rooms[$key]);
            $this->rooms = array_values($this->rooms);
            $this->server->removeConnectionFromRoom($this, $room);
        }
    }

    /**
     * @return array<string>
     */
    public function getRooms(): array
    {
        return $this->rooms;
    }

    public function isInRoom(string $room): bool
    {
        return in_array($room, $this->rooms, true);
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->upgradeRequest;
    }

    public function getRemoteAddress(): string
    {
        return $this->tcpConnection->getRemoteAddress();
    }

    public function getRemotePort(): int
    {
        return $this->tcpConnection->getRemotePort();
    }

    public function getTcpConnection(): TcpConnection
    {
        return $this->tcpConnection;
    }

    public function getLastPong(): float
    {
        return $this->lastPong;
    }

    public function getLastPing(): ?float
    {
        return $this->lastPing;
    }

    public function getServer(): WebSocketServer
    {
        return $this->server;
    }
}
