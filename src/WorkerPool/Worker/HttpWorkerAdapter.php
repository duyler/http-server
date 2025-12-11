<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WorkerPool\Worker;

use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\WorkerPool\Exception\WorkerPoolException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Socket;
use Throwable;

class HttpWorkerAdapter
{
    private const int READ_BUFFER_SIZE = 8192;
    private const int MAX_REQUEST_SIZE = 10485760;
    private const int SOCKET_TIMEOUT = 30;

    private readonly HttpParser $httpParser;
    private readonly Psr17Factory $psr17Factory;

    public function __construct()
    {
        $this->httpParser = new HttpParser();
        $this->psr17Factory = new Psr17Factory();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function handleConnection(Socket $clientSocket, array $metadata = []): void
    {
        socket_set_option($clientSocket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => self::SOCKET_TIMEOUT,
            'usec' => 0,
        ]);

        socket_set_option($clientSocket, SOL_SOCKET, SO_SNDTIMEO, [
            'sec' => self::SOCKET_TIMEOUT,
            'usec' => 0,
        ]);

        try {
            $rawRequest = $this->readRequest($clientSocket);

            if ($rawRequest === null || $rawRequest === '') {
                $this->sendErrorResponse($clientSocket, 400, 'Bad Request');
                return;
            }

            $request = $this->parseRawRequest($rawRequest, $metadata);

            if ($request === null) {
                $this->sendErrorResponse($clientSocket, 400, 'Invalid HTTP Request');
                return;
            }

            $response = $this->processRequest($request);

            $this->sendResponse($clientSocket, $response);
        } catch (Throwable $e) {
            $this->sendErrorResponse($clientSocket, 500, $e->getMessage());
        } finally {
            socket_close($clientSocket);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function parseRawRequest(string $rawRequest, array $metadata): ?ServerRequestInterface
    {
        try {
            if (!$this->httpParser->hasCompleteHeaders($rawRequest)) {
                return null;
            }

            [$headerBlock, $body] = $this->httpParser->splitHeadersAndBody($rawRequest);

            $lines = explode("\r\n", $headerBlock);
            $requestLine = array_shift($lines);

            if ($requestLine === '') {
                return null;
            }

            $requestLineParsed = $this->httpParser->parseRequestLine($requestLine);
            $headers = $this->httpParser->parseHeaders(implode("\r\n", $lines));

            $uri = $this->psr17Factory->createUri($requestLineParsed['uri']);

            $serverParams = [
                'REQUEST_METHOD' => $requestLineParsed['method'],
                'REQUEST_URI' => $requestLineParsed['uri'],
                'SERVER_PROTOCOL' => 'HTTP/' . $requestLineParsed['version'],
            ];

            $request = $this->psr17Factory->createServerRequest(
                $requestLineParsed['method'],
                $uri,
                $serverParams,
            );

            foreach ($headers as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            if ($body !== '') {
                $stream = $this->psr17Factory->createStream($body);
                $request = $request->withBody($stream);
            }

            foreach ($metadata as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }

            return $request;
        } catch (Throwable) {
            return null;
        }
    }

    private function processRequest(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            status: 200,
            headers: ['Content-Type' => 'text/plain'],
            body: 'Hello from Worker Pool!',
        );
    }

    private function readRequest(Socket $socket): ?string
    {
        $buffer = '';
        $headersParsed = false;
        $contentLength = 0;

        while (true) {
            $chunk = socket_read($socket, self::READ_BUFFER_SIZE);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;

            if (!$headersParsed && str_contains($buffer, "\r\n\r\n")) {
                $headersParsed = true;

                $matchResult = preg_match('/Content-Length:\s*(\d+)/i', $buffer, $matches);
                if ($matchResult === 1) {
                    $contentLength = (int) $matches[1];
                }

                $parts = explode("\r\n\r\n", $buffer, 2);
                [$headers, $body] = count($parts) === 2 ? $parts : [$parts[0], ''];

                if (strlen($body) >= $contentLength) {
                    break;
                }
            }

            if (strlen($buffer) > self::MAX_REQUEST_SIZE) {
                throw new WorkerPoolException('Request too large');
            }

            if (!$headersParsed && strlen($buffer) > 16384) {
                break;
            }
        }

        return $buffer !== '' ? $buffer : null;
    }

    private function sendResponse(Socket $socket, ResponseInterface $response): void
    {
        $rawResponse = $this->serializeResponse($response);

        socket_write($socket, $rawResponse);
    }

    private function sendErrorResponse(Socket $socket, int $statusCode, string $message): void
    {
        $response = new Response(
            status: $statusCode,
            headers: ['Content-Type' => 'text/plain'],
            body: $message,
        );

        $this->sendResponse($socket, $response);
    }

    private function serializeResponse(ResponseInterface $response): string
    {
        $status = sprintf(
            "HTTP/%s %d %s\r\n",
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        );

        $headers = '';
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers .= sprintf("%s: %s\r\n", $name, $value);
            }
        }

        $body = (string) $response->getBody();

        if (!$response->hasHeader('Content-Length')) {
            $headers .= sprintf("Content-Length: %d\r\n", strlen($body));
        }

        return $status . $headers . "\r\n" . $body;
    }
}
