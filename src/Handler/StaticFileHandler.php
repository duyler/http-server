<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Handler;

use Duyler\HttpServer\Security\AuditLoggerInterface;
use Duyler\HttpServer\Util\ClientIpResolver;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

final class StaticFileHandler
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
    ];

    /** @var array<string, array{content: string, mtime: int, etag: string, size: int, lruNode: object}> */
    private array $cache = [];
    private int $cacheSize = 0;
    /** @var object|null LRU list head (most recently used) */
    private ?object $lruHead = null;
    /** @var object|null LRU list tail (least recently used) */
    private ?object $lruTail = null;

    private readonly string|false $realPublicPath;

    public function __construct(
        private readonly string $publicPath,
        private readonly bool $enableCache = true,
        private readonly int $maxCacheSize = 52428800,
        private readonly int $maxCacheFiles = 1000,
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {
        $this->realPublicPath = realpath($this->publicPath);
    }

    public function isStaticFile(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        if ('/' === $path || '' === $path) {
            return false;
        }

        $filePath = $this->publicPath . $path;
        $realPath = realpath($filePath);

        if (false === $realPath) {
            return false;
        }

        if (false === $this->realPublicPath) {
            return false;
        }

        return str_starts_with($realPath, $this->realPublicPath) && is_file($realPath);
    }

    public function handle(ServerRequestInterface $request): ?ResponseInterface
    {
        $path = $request->getUri()->getPath();

        $filePath = $this->publicPath . $path;

        $realPath = realpath($filePath);

        if (false === $realPath) {
            return null;
        }

        if (false === $this->realPublicPath) {
            return null;
        }

        if (!str_starts_with($realPath, $this->realPublicPath)) {
            $this->auditLogger?->logPathTraversalAttempt(ClientIpResolver::resolve($request), $path);
            return null;
        }

        if (!is_file($realPath)) {
            return null;
        }

        if (!is_readable($realPath)) {
            return new Response(403, [], 'Forbidden');
        }

        $mtime = filemtime($realPath);
        if (false === $mtime) {
            return new Response(500, [], 'Internal Server Error');
        }

        $filesize = filesize($realPath);
        if (false === $filesize) {
            return new Response(500, [], 'Internal Server Error');
        }

        $etag = sprintf('"%x-%x"', $mtime, $filesize);

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($etag === $ifNoneMatch) {
            return new Response(304);
        }

        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
        $modifiedTime = strtotime($ifModifiedSince);
        if ('' !== $ifModifiedSince && false !== $modifiedTime && $modifiedTime >= $mtime) {
            return new Response(304);
        }

        $mimeType = $this->getMimeType($realPath);

        if ($filesize > $this->maxCacheSize) {
            return $this->streamFile($realPath, $mimeType, $mtime, $etag, $filesize);
        }

        $content = $this->getFileContent($realPath, $mtime, $etag, $filesize);
        if (null === $content) {
            return new Response(500, [], 'Internal Server Error');
        }

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

    private function streamFile(
        string $filePath,
        string $mimeType,
        int $mtime,
        string $etag,
        int $filesize,
    ): ResponseInterface {
        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            return new Response(500, [], 'Failed to open file');
        }
        $stream = \Nyholm\Psr7\Stream::create($handle);

        return new Response(
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Length' => (string) $filesize,
                'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=3600',
            ],
            $stream,
        );
    }

    private function getFileContent(string $filePath, int $mtime, string $etag, int $filesize): ?string
    {
        if (false === $this->enableCache) {
            $content = file_get_contents($filePath);
            return false !== $content ? $content : null;
        }

        if (isset($this->cache[$filePath])) {
            $cached = $this->cache[$filePath];

            if ($cached['mtime'] === $mtime && $cached['etag'] === $etag) {
                $this->moveToHead($cached['lruNode']);
                return $cached['content'];
            }

            $this->removeFromList($cached['lruNode']);
            $this->cacheSize -= $cached['size'];
            unset($this->cache[$filePath]);
        }

        if ($this->cacheSize + $filesize > $this->maxCacheSize) {
            $content = file_get_contents($filePath);
            return false !== $content ? $content : null;
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            return null;
        }

        $this->evictIfNeeded($filesize);

        $lruNode = $this->createLruNode($filePath);

        $this->cache[$filePath] = [
            'content' => $content,
            'mtime' => $mtime,
            'etag' => $etag,
            'size' => $filesize,
            'lruNode' => $lruNode,
        ];
        $this->cacheSize += $filesize;

        return $content;
    }

    private function evictIfNeeded(int $newFileSize): void
    {
        while (
            (count($this->cache) >= $this->maxCacheFiles
             || $this->cacheSize + $newFileSize > $this->maxCacheSize)
            && count($this->cache) > 0
        ) {
            $this->evictLeastRecentlyUsed();
        }
    }

    private function evictLeastRecentlyUsed(): void
    {
        if (null === $this->lruTail) {
            return;
        }

        /** @var string $oldestPath */
        $oldestPath = $this->lruTail->path;
        $this->removeFromList($this->lruTail);

        if (isset($this->cache[$oldestPath])) {
            $this->cacheSize -= $this->cache[$oldestPath]['size'];
            unset($this->cache[$oldestPath]);
        }
    }

    private function createLruNode(string $path): object
    {
        $node = new stdClass();
        $node->path = $path;
        $node->prev = null;
        $node->next = null;

        if (null === $this->lruHead) {
            $this->lruHead = $node;
            $this->lruTail = $node;
        } else {
            $node->next = $this->lruHead;
            $this->lruHead->prev = $node;
            $this->lruHead = $node;
        }

        return $node;
    }

    private function moveToHead(object $node): void
    {
        if ($node === $this->lruHead) {
            return;
        }

        $this->removeFromList($node);

        $node->prev = null;
        $node->next = $this->lruHead;

        if (null !== $this->lruHead) {
            $this->lruHead->prev = $node;
        }

        $this->lruHead = $node;

        if (null === $this->lruTail) {
            $this->lruTail = $node;
        }
    }

    private function removeFromList(object $node): void
    {
        /** @var object|null $prev */
        $prev = $node->prev;
        /** @var object|null $next */
        $next = $node->next;

        if (null !== $prev) {
            $prev->next = $next;
        } else {
            $this->lruHead = $next;
        }

        if (null !== $next) {
            $next->prev = $prev;
        } else {
            $this->lruTail = $prev;
        }

        $node->prev = null;
        $node->next = null;
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
            'max_files' => $this->maxCacheFiles,
        ];
    }

    public function clearCache(): void
    {
        $this->cache = [];
        $this->cacheSize = 0;
        $this->lruHead = null;
        $this->lruTail = null;
    }
}
