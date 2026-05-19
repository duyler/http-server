<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Support;

use Socket;

class PlatformHelper
{
    public static function isDocker(): bool
    {
        return file_exists('/.dockerenv') || file_exists('/run/.containerenv');
    }

    public static function isMacOS(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function supportsSCMRights(): bool
    {
        if (!defined('SCM_RIGHTS')) {
            return false;
        }

        if (!function_exists('socket_sendmsg') || !function_exists('socket_recvmsg')) {
            return false;
        }

        if (self::isWindows()) {
            return false;
        }

        if (self::isMacOS()) {
            return false;
        }

        static $cachedResult = null;
        if (null !== $cachedResult) {
            return $cachedResult;
        }

        $cachedResult = self::testFdPassingWorks();

        return $cachedResult;
    }

    private static function testFdPassingWorks(): bool
    {
        $previousErrorReporting = error_reporting(0);

        $server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $server) {
            error_reporting($previousErrorReporting);
            return false;
        }

        socket_set_option($server, SOL_SOCKET, SO_REUSEADDR, 1);
        if (false === socket_bind($server, '127.0.0.1', 0)) {
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        if (false === socket_listen($server, 1)) {
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        socket_getsockname($server, $addr, $port);

        $client = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (false === $client) {
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        if (false === socket_connect($client, $addr, $port)) {
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        $accepted = socket_accept($server);
        if (false === $accepted) {
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        socket_write($client, 'test_data');

        $pair = [];
        if (false === socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair)) {
            socket_close($accepted);
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        [$sock1, $sock2] = $pair;

        $msg = [
            'iov' => ['x'],
            'control' => [
                [
                    'level' => SOL_SOCKET,
                    'type' => SCM_RIGHTS,
                    'data' => [$accepted],
                ],
            ],
        ];

        $sendResult = socket_sendmsg($sock1, $msg, 0);
        if (false === $sendResult) {
            socket_close($sock1);
            socket_close($sock2);
            socket_close($accepted);
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        usleep(10000);

        $rmsg = [
            'iov' => [''],
            'control' => [],
            'controllen' => 256,
        ];

        $recvResult = socket_recvmsg($sock2, $rmsg, 0);
        if (false === $recvResult) {
            socket_close($sock1);
            socket_close($sock2);
            socket_close($accepted);
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        if (!isset($rmsg['control'][0]['data'][0])) {
            socket_close($sock1);
            socket_close($sock2);
            socket_close($accepted);
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        $recvFd = $rmsg['control'][0]['data'][0];
        if (!is_resource($recvFd) && !($recvFd instanceof Socket)) {
            socket_close($sock1);
            socket_close($sock2);
            socket_close($accepted);
            socket_close($client);
            socket_close($server);
            error_reporting($previousErrorReporting);
            return false;
        }

        $isFunctional = false;
        if (is_resource($recvFd)) {
            stream_set_blocking($recvFd, false);
            $data = fread($recvFd, 1024);
            $isFunctional = strlen($data) > 0;
        } elseif ($recvFd instanceof Socket) {
            socket_set_nonblock($recvFd);
            $data = socket_read($recvFd, 1024);
            $isFunctional = strlen((string) $data) > 0;
        }

        socket_close($sock1);
        socket_close($sock2);
        socket_close($accepted);
        socket_close($client);
        socket_close($server);

        error_reporting($previousErrorReporting);

        return $isFunctional;
    }

    public static function supportsSocketReusePort(): bool
    {
        if (!defined('SO_REUSEPORT')) {
            return false;
        }

        if (self::isWindows()) {
            return false;
        }

        if (self::isMacOS() && !self::isDocker()) {
            return false;
        }

        return true;
    }

    public static function getPlatformName(): string
    {
        if (self::isDocker()) {
            return 'Docker (' . PHP_OS_FAMILY . ')';
        }

        return PHP_OS_FAMILY;
    }

    public static function getSkipReason(string $feature): string
    {
        $platform = self::getPlatformName();

        return match ($feature) {
            'scm_rights' => "SCM_RIGHTS FD passing is not functional on {$platform}. FD transfer works but received FDs cannot read/write data.",
            'so_reuseport' => "SO_REUSEPORT not supported on {$platform}",
            'fork' => "Process forking not supported on {$platform}",
            default => "Feature '{$feature}' not supported on {$platform}",
        };
    }
}
