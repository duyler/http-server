<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Support;

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

        if (self::isDocker()) {
            return false;
        }

        return true;
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
            'scm_rights' => "SCM_RIGHTS not supported on {$platform}",
            'so_reuseport' => "SO_REUSEPORT not supported on {$platform}",
            'fork' => "Process forking not supported on {$platform}",
            default => "Feature '{$feature}' not supported on {$platform}",
        };
    }
}
