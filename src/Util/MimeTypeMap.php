<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Util;

final class MimeTypeMap
{
    /** @var array<string, string> */
    private const array MIME_TYPES = [
        'html' => 'text/html',
        'htm' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
    ];

    public static function getFromExtension(string $extension): string
    {
        return self::MIME_TYPES[strtolower($extension)] ?? 'application/octet-stream';
    }

    public static function getFromFilePath(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }
}
