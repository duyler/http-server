<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\IPC;

use Duyler\HttpServer\WorkerPool\Exception\IPCException;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Socket;

class FdPasser
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}
    /**
     * Проверяет, поддерживается ли SCM_RIGHTS в текущей системе
     */
    public function isSupported(): bool
    {
        // SCM_RIGHTS хорошо работает на Linux
        // На macOS есть проблемы
        // В Docker зависит от конфигурации

        if (PHP_OS_FAMILY !== 'Linux') {
            return false;
        }

        // Проверяем, что socket_sendmsg/socket_recvmsg доступны
        return function_exists('socket_sendmsg') && function_exists('socket_recvmsg');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function sendFd(Socket $controlSocket, Socket $fdToSend, array $metadata = []): bool
    {
        if (!function_exists('socket_sendmsg')) {
            throw new IPCException('socket_sendmsg() is not available');
        }

        if (!defined('SCM_RIGHTS')) {
            throw new IPCException('SCM_RIGHTS is not defined');
        }

        $this->logger->debug('Sending FD with metadata', ['metadata' => $metadata]);

        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);
        if ($metadataJson === '[]' || $metadataJson === '') {
            $metadataJson = '{}';
        }

        $message = [
            'iov' => [$metadataJson],
            'control' => [
                [
                    'level' => SOL_SOCKET,
                    'type' => SCM_RIGHTS,
                    'data' => [$fdToSend],
                ],
            ],
        ];

        $result = socket_sendmsg($controlSocket, $message, 0);

        if ($result === false) {
            $this->logger->error('sendmsg failed', [
                'error' => socket_strerror(socket_last_error($controlSocket)),
            ]);
        } else {
            $this->logger->debug('FD sent', ['bytes' => $result]);
        }

        return $result !== false;
    }

    /**
     * @return array{fd: Socket, metadata: array<string, mixed>}|null
     */
    public function receiveFd(Socket $controlSocket): ?array
    {
        static $callCount = 0;
        $callCount++;

        if (!function_exists('socket_recvmsg')) {
            throw new IPCException('socket_recvmsg() is not available');
        }

        if (!defined('SCM_RIGHTS')) {
            throw new IPCException('SCM_RIGHTS is not defined');
        }

        $message = [
            'iov' => [''],
            'control' => [],
            'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_RIGHTS),
        ];

        $result = socket_recvmsg($controlSocket, $message, MSG_DONTWAIT);

        if ($result === false || $result === 0) {
            $errno = socket_last_error($controlSocket);
            if ($errno !== 11 && $errno !== 0 && $callCount % 1000 === 0) {
                $this->logger->debug('recvmsg error', [
                    'errno' => $errno,
                    'error' => socket_strerror($errno),
                ]);
            }
            return null;
        }

        if ($result === 0) {
            if ($callCount % 1000 === 0) {
                $this->logger->debug('recvmsg returned 0 (no data)');
            }
            return null;
        }

        $this->logger->debug('recvmsg returned bytes', ['bytes' => $result]);
        $this->logger->debug('Message type', ['type' => gettype($message)]);

        if (!is_array($message)) {
            $this->logger->error('Message is not an array', ['type' => gettype($message)]);
            return null;
        }

        $this->logger->debug('Message keys', ['keys' => array_keys($message)]);

        if (!isset($message['control'][0]['data'][0])) {
            $this->logger->error('No control data received');
            if (isset($message['control'])) {
                $this->logger->debug('Control array', ['control' => $message['control']]);
            } else {
                $this->logger->debug('Control key does not exist');
            }
            return null;
        }

        $receivedFd = $message['control'][0]['data'][0];

        if (!$receivedFd instanceof Socket) {
            $this->logger->error('Received FD is not a Socket', ['type' => gettype($receivedFd)]);
            return null;
        }

        $metadataJson = $message['iov'][0] ?? '{}';
        $metadataJson = rtrim($metadataJson, "\0");

        if ($metadataJson === '') {
            $metadataJson = '{}';
        }

        try {
            $metadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Failed to decode metadata', ['error' => $e->getMessage()]);
            $metadata = [];
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $this->logger->debug('FD received successfully', ['metadata' => $metadata]);

        return [
            'fd' => $receivedFd,
            'metadata' => $metadata,
        ];
    }
}
