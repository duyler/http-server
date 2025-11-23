<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Handler;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StaticFileHandler
{
    private const MIME_TYPES = [
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
    ];

    /** @var array<string, array{content: string, mtime: int, etag: string}> */
    private array $cache = [];
    private int $cacheSize = 0;

    public function __construct(
        private readonly string $publicPath,
        private readonly bool $enableCache = true,
        private readonly int $maxCacheSize = 52428800,
    ) {}

    public function isStaticFile(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        if ($path === '/' || $path === '') {
            return false;
        }

        $filePath = $this->publicPath . $path;
        $realPath = realpath($filePath);

        if ($realPath === false) {
            return false;
        }

        $realPublicPath = realpath($this->publicPath);
        if ($realPublicPath === false) {
            return false;
        }

        return str_starts_with($realPath, $realPublicPath) && is_file($realPath);
    }

    public function handle(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $filePath = $this->publicPath . $path;

        $realPath = realpath($filePath);

        if ($realPath === false) {
            return null;
        }

        $realPublicPath = realpath($this->publicPath);
        if ($realPublicPath === false || !str_starts_with($realPath, $realPublicPath)) {
            return null;
        }

        if (!is_file($realPath)) {
            return null;
        }

        if (!is_readable($realPath)) {
            return new Response(403, [], 'Forbidden');
        }

        $mtime = filemtime($realPath);
        if ($mtime === false) {
            return new Response(500, [], 'Internal Server Error');
        }

        $filesize = filesize($realPath);
        if ($filesize === false) {
            return new Response(500, [], 'Internal Server Error');
        }

        $etag = sprintf('"%x-%x"', $mtime, $filesize);

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch === $etag) {
            return new Response(304);
        }

        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
        $modifiedTime = strtotime($ifModifiedSince);
        if ($ifModifiedSince !== '' && $modifiedTime !== false && $modifiedTime >= $mtime) {
            return new Response(304);
        }

        $content = $this->getFileContent($realPath, $mtime, $etag);
        if ($content === null) {
            return new Response(500, [], 'Internal Server Error');
        }

        $mimeType = $this->getMimeType($realPath);

        return new Response(
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) strlen($content),
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=3600',
            ],
            $content,
        );
    }

    private function getFileContent(string $filePath, int $mtime, string $etag): ?string
    {
        if (!$this->enableCache) {
            $content = file_get_contents($filePath);
            return $content !== false ? $content : null;
        }

        if (isset($this->cache[$filePath])) {
            $cached = $this->cache[$filePath];

            if ($cached['mtime'] === $mtime && $cached['etag'] === $etag) {
                return $cached['content'];
            }

            $this->cacheSize -= strlen($cached['content']);
            unset($this->cache[$filePath]);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $size = strlen($content);

        if ($this->cacheSize + $size <= $this->maxCacheSize) {
            $this->cache[$filePath] = [
                'content' => $content,
                'mtime' => $mtime,
                'etag' => $etag,
            ];
            $this->cacheSize += $size;
        }

        return $content;
    }

    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }

    /**
     * @return array<string, int>
     */
    public function getCacheStats(): array
    {
        return [
            'entries' => count($this->cache),
            'size' => $this->cacheSize,
            'max_size' => $this->maxCacheSize,
        ];
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->cacheSize = 0;
    }
}
