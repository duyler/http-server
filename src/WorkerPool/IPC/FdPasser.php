<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\IPC;

use Duyler\HttpServer\WorkerPool\Exception\IPCException;
use JsonException;
use Socket;

class FdPasser
{
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

        error_log("[FdPasser] Sending FD with metadata: " . json_encode($metadata));

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
            error_log("[FdPasser] ERROR: sendmsg failed: " . socket_strerror(socket_last_error($controlSocket)));
        } else {
            error_log("[FdPasser] ✅ FD sent, bytes: $result");
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
                error_log("[FdPasser] recvmsg error (errno=$errno): " . socket_strerror($errno));
            }
            return null;
        }

        if ($result === 0) {
            if ($callCount % 1000 === 0) {
                error_log("[FdPasser] recvmsg returned 0 (no data)");
            }
            return null;
        }

        error_log("[FdPasser] recvmsg returned $result bytes");
        error_log("[FdPasser] Message type: " . gettype($message));
        
        if (!is_array($message)) {
            error_log("[FdPasser] ERROR: Message is not an array! Got: " . gettype($message));
            return null;
        }
        
        error_log("[FdPasser] Message keys: " . implode(', ', array_keys($message)));

        if (!isset($message['control'][0]['data'][0])) {
            error_log("[FdPasser] ERROR: No control data received!");
            if (isset($message['control'])) {
                error_log("[FdPasser] Control array: " . json_encode($message['control']));
            } else {
                error_log("[FdPasser] Control key does not exist");
            }
            return null;
        }

        $receivedFd = $message['control'][0]['data'][0];

        if (!$receivedFd instanceof Socket) {
            error_log("[FdPasser] ERROR: Received FD is not a Socket! Type: " . gettype($receivedFd));
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
            error_log("[FdPasser] ERROR: Failed to decode metadata: " . $e->getMessage());
            $metadata = [];
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        error_log("[FdPasser] ✅✅✅ FD received successfully! Metadata: " . json_encode($metadata));

        return [
            'fd' => $receivedFd,
            'metadata' => $metadata,
        ];
    }
}
