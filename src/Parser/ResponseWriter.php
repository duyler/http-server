<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Parser;

use Psr\Http\Message\ResponseInterface;

class ResponseWriter
{
    private const HTTP_STATUS_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        413 => 'Payload Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function write(ResponseInterface $response): string
    {
        $parts = [];
        $parts[] = $this->buildStatusLine($response);
        $parts[] = $this->buildHeaders($response);
        $parts[] = "\r\n";
        $parts[] = $this->getBody($response);

        return implode('', $parts);
    }

    public function writeChunked(ResponseInterface $response, callable $callback): void
    {
        $parts = [];
        $parts[] = $this->buildStatusLine($response);

        $response = $response->withHeader('Transfer-Encoding', 'chunked');
        $parts[] = $this->buildHeaders($response);
        $parts[] = "\r\n";

        $callback(implode('', $parts));

        $body = $response->getBody();
        $body->rewind();

        $chunkSize = 8192;

        while (!$body->eof()) {
            $chunk = $body->read($chunkSize);
            if ($chunk === '') {
                break;
            }

            $callback(sprintf("%x\r\n%s\r\n", strlen($chunk), $chunk));
        }

        $callback("0\r\n\r\n");
    }

    public function writeBuffered(ResponseInterface $response, callable $callback, int $bufferSize = 8192): void
    {
        $parts = [];
        $parts[] = $this->buildStatusLine($response);
        $parts[] = $this->buildHeaders($response);
        $parts[] = "\r\n";

        $headers = implode('', $parts);
        $body = $response->getBody();
        $body->rewind();

        $bodySize = $body->getSize();

        if ($bodySize === null || $bodySize <= $bufferSize) {
            $callback($headers . $body->getContents());
            return;
        }

        $buffer = $headers;
        $bufferLength = strlen($headers);

        while (!$body->eof()) {
            $chunk = $body->read($bufferSize - $bufferLength);
            if ($chunk === '') {
                break;
            }

            $buffer .= $chunk;
            $bufferLength = strlen($buffer);

            if ($bufferLength >= $bufferSize) {
                $callback($buffer);
                $buffer = '';
                $bufferLength = 0;
            }
        }

        if ($bufferLength > 0) {
            $callback($buffer);
        }
    }

    private function buildStatusLine(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        if ($reasonPhrase === '') {
            $reasonPhrase = self::HTTP_STATUS_PHRASES[$statusCode] ?? 'Unknown';
        }

        return sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $statusCode,
            $reasonPhrase,
        );
    }

    private function buildHeaders(ResponseInterface $response): string
    {
        $parts = [];

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $parts[] = sprintf("%s: %s\r\n", $name, $value);
            }
        }

        return implode('', $parts);
    }

    private function getBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $body->rewind();
        return $body->getContents();
    }
}
