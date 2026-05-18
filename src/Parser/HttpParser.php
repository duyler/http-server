<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Parser;

use Duyler\HttpServer\Exception\ParseException;

final class HttpParser
{
    private const string HTTP_VERSION_PATTERN = '/^HTTP\/(\d+\.\d+)$/';
    private const string HEADER_PATTERN = '/^([^:\s]+):\s*(.+)$/m';

    /** @var array<string, true> */
    private const array SINGULAR_HEADERS = [
        'Content-Length' => true,
        'Content-Type' => true,
        'Host' => true,
        'Authorization' => true,
        'Transfer-Encoding' => true,
    ];

    /** @var array<string, true> */
    private const array VALID_METHODS = [
        'GET' => true,
        'POST' => true,
        'PUT' => true,
        'DELETE' => true,
        'PATCH' => true,
        'HEAD' => true,
        'OPTIONS' => true,
        'TRACE' => true,
        'CONNECT' => true,
    ];

    /** @var array<string, string> */
    private array $headerNameCache = [];

    private int $headerCacheSize = 0;

    public function __construct(
        private readonly int $headerCacheLimit = 100,
    ) {}

    /**
     * @return array{method: string, uri: string, version: string}
     */
    public function parseRequestLine(string $line): array
    {
        $line = rtrim($line, "\r\n");

        if ('' === $line) {
            throw new ParseException('Empty request line');
        }

        $parts = explode(' ', $line, 3);

        if (count($parts) !== 3) {
            throw new ParseException(sprintf('Invalid request line format: "%s"', $line));
        }

        [$method, $uri, $protocol] = $parts;

        if ('' === $uri) {
            throw new ParseException('Empty URI in request line');
        }

        $methodUpper = strtoupper($method);

        if (!isset(self::VALID_METHODS[$methodUpper])) {
            throw new ParseException(sprintf('Invalid HTTP method: %s', $method));
        }

        if (!preg_match(self::HTTP_VERSION_PATTERN, $protocol, $matches)) {
            throw new ParseException(sprintf('Invalid HTTP version: %s', $protocol));
        }

        return [
            'method' => $methodUpper,
            'uri' => $uri,
            'version' => $matches[1],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $headerBlock = trim($headerBlock);

        if ('' === $headerBlock) {
            return [];
        }

        if (!str_contains($headerBlock, "\r\n ") && !str_contains($headerBlock, "\r\n\t")) {
            $matchCount = preg_match_all(self::HEADER_PATTERN, $headerBlock, $matches, PREG_SET_ORDER);

            if ($matchCount > 0) {
                foreach ($matches as $match) {
                    $normalizedName = $this->normalizeHeaderName($match[1]);

                    if (isset(self::SINGULAR_HEADERS[$normalizedName]) && isset($headers[$normalizedName])) {
                        throw new ParseException(
                            sprintf('Duplicate header not allowed: %s', $normalizedName),
                        );
                    }

                    if (!isset($headers[$normalizedName])) {
                        $headers[$normalizedName] = [];
                    }

                    $headers[$normalizedName][] = trim($match[2]);
                }

                return $headers;
            }
        }

        $lines = explode("\r\n", $headerBlock);
        $currentHeader = null;

        foreach ($lines as $line) {
            if ('' === $line) {
                continue;
            }

            if ($line[0] === ' ' || $line[0] === "\t") {
                if (null === $currentHeader) {
                    throw new ParseException('Invalid header continuation');
                }
                $headers[$currentHeader][count($headers[$currentHeader]) - 1] .= ' ' . trim($line);
                continue;
            }

            $colonPos = strpos($line, ':');
            if (false === $colonPos) {
                throw new ParseException(sprintf('Invalid header format: %s', $line));
            }

            $name = substr($line, 0, $colonPos);
            $value = ltrim(substr($line, $colonPos + 1));

            $normalizedName = $this->normalizeHeaderName($name);
            $currentHeader = $normalizedName;

            if (isset(self::SINGULAR_HEADERS[$normalizedName]) && isset($headers[$normalizedName])) {
                throw new ParseException(
                    sprintf('Duplicate header not allowed: %s', $normalizedName),
                );
            }

            if (!isset($headers[$normalizedName])) {
                $headers[$normalizedName] = [];
            }

            $headers[$normalizedName][] = $value;
        }

        return $headers;
    }

    public function hasCompleteHeaders(string $buffer): bool
    {
        return str_contains($buffer, "\r\n\r\n");
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function splitHeadersAndBody(string $buffer): array
    {
        $pos = strpos($buffer, "\r\n\r\n");

        if (false === $pos) {
            return [$buffer, ''];
        }

        return [
            substr($buffer, 0, $pos),
            substr($buffer, $pos + 4),
        ];
    }

    /**
     * @param array<string, array<array-key, string>> $headers
     */
    public function getContentLength(array $headers): int
    {
        if (!isset($headers['Content-Length'][0])) {
            return 0;
        }

        $value = $headers['Content-Length'][0];
        $length = (int) $value;

        if ($length < 0) {
            throw new ParseException('Invalid Content-Length value');
        }

        return $length;
    }

    /**
     * @param array<string, array<array-key, string>> $headers
     */
    public function isChunked(array $headers): bool
    {
        if (!isset($headers['Transfer-Encoding'])) {
            return false;
        }

        foreach ($headers['Transfer-Encoding'] as $value) {
            if (stripos($value, 'chunked') !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHeaderName(string $name): string
    {
        if (isset($this->headerNameCache[$name])) {
            return $this->headerNameCache[$name];
        }

        $normalized = str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($name))));

        if ($this->headerCacheSize >= $this->headerCacheLimit) {
            $this->headerNameCache = array_slice(
                $this->headerNameCache,
                (int) ($this->headerCacheLimit / 2),
                null,
                true,
            );
            $this->headerCacheSize = count($this->headerNameCache);
        }

        $this->headerNameCache[$name] = $normalized;
        $this->headerCacheSize++;

        return $normalized;
    }

    public function clearCache(): void
    {
        $this->headerNameCache = [];
        $this->headerCacheSize = 0;
    }
}
