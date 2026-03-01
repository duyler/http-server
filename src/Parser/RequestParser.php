<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Parser;

use Duyler\HttpServer\Upload\TempFileManager;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestParser
{
    public function __construct(
        private HttpParser $httpParser,
        private Psr17Factory $psr17Factory,
        private TempFileManager $tempFileManager,
    ) {}

    public function parse(string $rawRequest, string $remoteAddr, int $remotePort): ServerRequestInterface
    {
        [$headerBlock, $body] = $this->httpParser->splitHeadersAndBody($rawRequest);

        $lines = explode("\r\n", $headerBlock);
        $requestLine = array_shift($lines);

        if ('' === $requestLine) {
            throw new InvalidArgumentException('Empty request line');
        }

        $requestInfo = $this->httpParser->parseRequestLine($requestLine);
        $headers = $this->httpParser->parseHeaders(implode("\r\n", $lines));

        $uri = $this->psr17Factory->createUri($requestInfo['uri']);

        $serverParams = [
            'REMOTE_ADDR' => $remoteAddr,
            'REMOTE_PORT' => $remotePort,
            'REQUEST_METHOD' => $requestInfo['method'],
            'REQUEST_URI' => $requestInfo['uri'],
            'SERVER_PROTOCOL' => 'HTTP/' . $requestInfo['version'],
        ];

        $bodyStream = $this->psr17Factory->createStream($body);

        $request = new ServerRequest(
            $requestInfo['method'],
            $uri,
            $headers,
            $bodyStream,
            $requestInfo['version'],
            $serverParams,
        );

        $request = $this->parseQueryParams($request);
        $request = $this->parseCookies($request, $headers);
        $request = $this->parseBody($request, $headers, $body);

        return $request;
    }

    private function parseQueryParams(ServerRequestInterface $request): ServerRequestInterface
    {
        $query = $request->getUri()->getQuery();

        if ('' === $query) {
            return $request;
        }

        parse_str($query, $queryParams);

        return $request->withQueryParams($queryParams);
    }

    /**
     * @param array<string, array<array-key, string>> $headers
     */
    private function parseCookies(ServerRequestInterface $request, array $headers): ServerRequestInterface
    {
        if (!isset($headers['Cookie'])) {
            return $request;
        }

        $cookies = [];
        foreach ($headers['Cookie'] as $cookieHeader) {
            $pairs = explode(';', $cookieHeader);
            foreach ($pairs as $pair) {
                $parts = explode('=', trim($pair), 2);
                if (2 === count($parts)) {
                    $name = trim($parts[0]);
                    $value = urldecode($parts[1]);

                    if ($this->isValidCookieName($name) && $this->isValidCookieValue($value)) {
                        $cookies[$name] = $value;
                    }
                }
            }
        }

        return $request->withCookieParams($cookies);
    }

    private function isValidCookieName(string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        return preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) === 1;
    }

    private function isValidCookieValue(string $value): bool
    {
        $length = strlen($value);

        if ($length > 4096) {
            return false;
        }

        return !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /**
     * @param array<string, array<array-key, string>> $headers
     */
    private function parseBody(ServerRequestInterface $request, array $headers, string $body): ServerRequestInterface
    {
        if ('' === $body) {
            return $request;
        }

        $contentType = $headers['Content-Type'][0] ?? '';

        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $parsedBody);
            return $request->withParsedBody($parsedBody);
        }

        if (str_starts_with($contentType, 'application/json')) {
            /** @var array<string, mixed>|null $parsedBody */
            $parsedBody = json_decode($body, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($parsedBody)) {
                return $request->withParsedBody($parsedBody);
            }
        }

        if (str_starts_with($contentType, 'multipart/form-data')) {
            return $this->parseMultipart($request, $contentType, $body);
        }

        return $request;
    }

    private function parseMultipart(ServerRequestInterface $request, string $contentType, string $body): ServerRequestInterface
    {
        preg_match('/boundary=(?:"([^"]+)"|([^\s;]+))/', $contentType, $matches);

        if (!isset($matches[1]) && !isset($matches[2])) {
            return $request;
        }

        $rawBoundary = ($matches[1] !== '') ? $matches[1] : ($matches[2] ?? '');

        if (false === $this->isValidBoundary($rawBoundary)) {
            throw new InvalidArgumentException('Invalid multipart boundary');
        }

        $boundary = '--' . $rawBoundary;
        $parts = explode($boundary, $body);

        array_shift($parts);
        array_pop($parts);

        $parsedBody = [];
        $uploadedFiles = [];

        foreach ($parts as $part) {
            if ('' === trim($part) || '--' === trim($part)) {
                continue;
            }

            [$partHeaders, $partBody] = $this->httpParser->splitHeadersAndBody(trim($part));
            $partHeaders = $this->httpParser->parseHeaders($partHeaders);

            if (!isset($partHeaders['Content-Disposition'])) {
                continue;
            }

            preg_match('/name="([^"]+)"/', $partHeaders['Content-Disposition'][0], $nameMatch);
            $name = $nameMatch[1] ?? null;

            if (null === $name) {
                continue;
            }

            preg_match('/filename="([^"]+)"/', $partHeaders['Content-Disposition'][0], $fileMatch);

            if (isset($fileMatch[1])) {
                $tmpFile = $this->tempFileManager->create('upload_');
                file_put_contents($tmpFile, $partBody);

                $uploadedFiles[$name] = new UploadedFile(
                    $tmpFile,
                    strlen($partBody),
                    UPLOAD_ERR_OK,
                    $fileMatch[1],
                    $partHeaders['Content-Type'][0] ?? 'application/octet-stream',
                );
            } else {
                $parsedBody[$name] = rtrim($partBody, "\r\n");
            }
        }

        return $request
            ->withParsedBody($parsedBody)
            ->withUploadedFiles($uploadedFiles);
    }

    private function isValidBoundary(string $boundary): bool
    {
        $length = strlen($boundary);

        if ($length < 1 || $length > 70) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9\'()+_,.\/:=? -]+$/', $boundary)) {
            return false;
        }

        if (str_ends_with($boundary, ' ')) {
            return false;
        }

        return true;
    }
}
