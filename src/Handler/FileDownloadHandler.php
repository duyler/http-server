<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Handler;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

final class FileDownloadHandler
{
    private const int CHUNK_SIZE = 8192;

    public function download(string $filePath, ?string $filename = null, ?string $mimeType = null): ResponseInterface
    {
        $result = $this->validateAndOpenFile($filePath, $filename, $mimeType);
        if ($result instanceof Response) {
            return $result;
        }

        $mtime = filemtime($filePath);
        if (false === $mtime) {
            fclose($result['handle']);
            return new Response(500, [], 'Failed to get file modification time');
        }

        $stream = Stream::create($result['handle']);

        return new Response(
            200,
            [
                'Content-Type' => $result['mimeType'],
                'Content-Length' => (string) $result['fileSize'],
                'Content-Disposition' => sprintf('attachment; filename="%s"', $result['filename']),
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
        $result = $this->validateAndOpenFile($filePath, $filename, $mimeType);
        if ($result instanceof Response) {
            return $result;
        }

        if ($start < 0 || $start >= $result['fileSize'] || $end < $start || $end >= $result['fileSize']) {
            fclose($result['handle']);
            return new Response(416, ['Content-Range' => "bytes */{$result['fileSize']}"], 'Range not satisfiable');
        }

        if (-1 === fseek($result['handle'], $start)) {
            fclose($result['handle']);
            return new Response(500, [], 'Failed to seek in file');
        }

        $content = fread($result['handle'], $end - $start + 1);
        fclose($result['handle']);

        if (false === $content) {
            return new Response(500, [], 'Failed to read file');
        }

        return new Response(
            206,
            [
                'Content-Type' => $result['mimeType'],
                'Content-Length' => (string) ($end - $start + 1),
                'Content-Range' => sprintf('bytes %d-%d/%d', $start, $end, $result['fileSize']),
                'Content-Disposition' => sprintf('attachment; filename="%s"', $result['filename']),
                'Accept-Ranges' => 'bytes',
            ],
            $content,
        );
    }

    public function parseRangeHeader(string $rangeHeader, int $fileSize): ?array
    {
        if (!str_starts_with($rangeHeader, 'bytes=')) {
            return null;
        }

        $rangeSpec = substr($rangeHeader, 6);
        $ranges = explode(',', $rangeSpec);

        if (count($ranges) > 10) {
            return null;
        }

        $result = [];

        foreach ($ranges as $range) {
            $parts = explode('-', trim($range), 2);

            if (count($parts) !== 2) {
                return null;
            }

            $startStr = trim($parts[0]);
            $endStr = trim($parts[1]);

            $startEmpty = $startStr === '';
            $endEmpty = $endStr === '';

            if ($startEmpty && $endEmpty) {
                return null;
            }

            $start = null;
            $end = null;

            if (false === $startEmpty) {
                $start = $this->parseRangeValue($startStr);
                if (null === $start) {
                    return null;
                }
            }

            if (false === $endEmpty) {
                $end = $this->parseRangeValue($endStr);
                if (null === $end) {
                    return null;
                }
            }

            if ($startEmpty) {
                $start = max(0, $fileSize - ($end ?? 0));
                $end = $fileSize - 1;
            } elseif ($endEmpty) {
                $end = $fileSize - 1;
            }

            assert(null !== $start && null !== $end);

            if ($start > $end || $start >= $fileSize) {
                continue;
            }

            $end = min($end, $fileSize - 1);
            $result[] = ['start' => $start, 'end' => $end];
        }

        return $result === [] ? null : $result;
    }

    private function parseRangeValue(string $value): ?int
    {
        if (!preg_match('/^\d+$/', $value)) {
            return null;
        }

        if (strlen($value) > 19) {
            return null;
        }

        $intVal = (int) $value;

        if ($intVal < 0 || (string) $intVal !== $value) {
            return null;
        }

        return $intVal;
    }

    /**
     * @return array{handle: resource, fileSize: int, filename: string, mimeType: string}|Response
     */
    private function validateAndOpenFile(string $filePath, ?string $filename, ?string $mimeType): array|Response
    {
        if (!file_exists($filePath)) {
            return new Response(404, [], 'File not found');
        }

        if (!is_readable($filePath)) {
            return new Response(403, [], 'File not readable');
        }

        $fileSize = filesize($filePath);
        if (false === $fileSize) {
            return new Response(500, [], 'Failed to get file size');
        }

        $filename ??= basename($filePath);
        $mimeType ??= $this->guessMimeType($filePath);

        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            return new Response(500, [], 'Failed to open file');
        }

        return [
            'handle' => $handle,
            'fileSize' => $fileSize,
            'filename' => $filename,
            'mimeType' => $mimeType,
        ];
    }

    private function guessMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if (false !== $mime) {
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
