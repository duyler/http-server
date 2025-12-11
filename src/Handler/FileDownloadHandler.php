<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Handler;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

class FileDownloadHandler
{
    private const int CHUNK_SIZE = 8192;

    public function download(string $filePath, ?string $filename = null, ?string $mimeType = null): ResponseInterface
    {
        if (!file_exists($filePath)) {
            return new Response(404, [], 'File not found');
        }

        if (!is_readable($filePath)) {
            return new Response(403, [], 'File not readable');
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return new Response(500, [], 'Failed to get file size');
        }

        $mtime = filemtime($filePath);
        if ($mtime === false) {
            return new Response(500, [], 'Failed to get file modification time');
        }

        $filename ??= basename($filePath);
        $mimeType ??= $this->guessMimeType($filePath);

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return new Response(500, [], 'Failed to open file');
        }

        $stream = Stream::create($handle);

        return new Response(
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) $fileSize,
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
                'Accept-Ranges' => 'bytes',
            ],
            $stream,
        );
    }

    public function downloadRange(
        string $filePath,
        int $start,
        int $end,
        ?string $filename = null,
        ?string $mimeType = null,
    ): ResponseInterface {
        if (!file_exists($filePath)) {
            return new Response(404, [], 'File not found');
        }

        if (!is_readable($filePath)) {
            return new Response(403, [], 'File not readable');
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            return new Response(500, [], 'Failed to get file size');
        }

        if ($start < 0 || $start >= $fileSize || $end < $start || $end >= $fileSize) {
            return new Response(416, ['Content-Range' => "bytes */$fileSize"], 'Range not satisfiable');
        }

        $filename ??= basename($filePath);
        $mimeType ??= $this->guessMimeType($filePath);

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return new Response(500, [], 'Failed to open file');
        }

        if (fseek($handle, $start) === -1) {
            fclose($handle);
            return new Response(500, [], 'Failed to seek in file');
        }

        $content = fread($handle, $end - $start + 1);
        fclose($handle);

        if ($content === false) {
            return new Response(500, [], 'Failed to read file');
        }

        return new Response(
            206,
            [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) ($end - $start + 1),
                'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $fileSize),
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
                'Accept-Ranges' => 'bytes',
            ],
            $content,
        );
    }

    public function parseRangeHeader(string $rangeHeader, int $fileSize): ?array
    {
        if (!preg_match('/^bytes=(\d+)-(\d*)$/', $rangeHeader, $matches)) {
            return null;
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $fileSize - 1 : (int) $matches[2];

        if ($start < 0 || $start >= $fileSize || $end < $start || $end >= $fileSize) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    private function guessMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime !== false) {
                return $mime;
            }
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $mimeTypes = [
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
