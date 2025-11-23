<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Parser;

use Duyler\HttpServer\Exception\ParseException;

class HttpParser
{
    private const HTTP_VERSION_PATTERN = '/^HTTP\/(\d+\.\d+)$/';

    /**
     * @return array{method: string, uri: string, version: string}
     */
    public function parseRequestLine(string $line): array
    {
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            throw new ParseException('Empty request line');
        }

        $parts = explode(' ', $line, 3);

        if (count($parts) !== 3) {
            throw new ParseException(sprintf('Invalid request line format: "%s"', $line));
        }

        [$method, $uri, $protocol] = $parts;

        if ($uri === '') {
            throw new ParseException('Empty URI in request line');
        }

        if (!$this->isValidMethod($method)) {
            throw new ParseException(sprintf('Invalid HTTP method: %s', $method));
        }

        if (!preg_match(self::HTTP_VERSION_PATTERN, $protocol, $matches)) {
            throw new ParseException(sprintf('Invalid HTTP version: %s', $protocol));
        }

        return [
            'method' => strtoupper($method),
            'uri' => $uri,
            'version' => $matches[1],
        ];
    }

    /**
     * @return array<string, array<string>>
     */
    public function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($headerBlock));

        $currentHeader = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                if ($currentHeader === null) {
                    throw new ParseException('Invalid header continuation');
                }
                $headers[$currentHeader][count($headers[$currentHeader]) - 1] .= ' ' . trim($line);
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                throw new ParseException(sprintf('Invalid header format: %s', $line));
            }

            $name = substr($line, 0, $colonPos);
            $value = trim(substr($line, $colonPos + 1));

            $normalizedName = $this->normalizeHeaderName($name);
            $currentHeader = $normalizedName;

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

        if ($pos === false) {
            return [$buffer, ''];
        }

        $headers = substr($buffer, 0, $pos);
        $body = substr($buffer, $pos + 4);

        return [$headers, $body];
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function getContentLength(array $headers): int
    {
        if (!isset($headers['Content-Length'])) {
            return 0;
        }

        $value = $headers['Content-Length'][0] ?? '0';
        $length = (int) $value;

        if ($length < 0) {
            throw new ParseException('Invalid Content-Length value');
        }

        return $length;
    }

    /**
     * @param array<string, array<int, string>> $headers
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

    private function isValidMethod(string $method): bool
    {
        $validMethods = [
            'GET', 'POST', 'PUT', 'DELETE', 'PATCH',
            'HEAD', 'OPTIONS', 'TRACE', 'CONNECT',
        ];

        return in_array(strtoupper($method), $validMethods, true);
    }

    private function normalizeHeaderName(string $name): string
    {
        return str_replace(' ', '-', ucwords(str_replace('-', ' ', strtolower($name))));
    }
}
