<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionInterface;
use Duyler\HttpServer\Parser\ResponseWriter;
use Nyholm\Psr7\Response;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ResponseSender implements ResponseSenderInterface
{
    public function __construct(
        private ServerConfig $config,
        private ResponseWriter $responseWriter,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    #[Override]
    public function send(ConnectionInterface $connection, ResponseInterface $response): void
    {
        if (false === $connection->isValid()) {
            return;
        }

        if (false === $response->hasHeader('Content-Length')) {
            $body = $response->getBody();
            $size = $body->getSize();

            if (null !== $size) {
                $response = $response->withHeader('Content-Length', (string) $size);
            } else {
                $bodyContents = (string) $body;
                $size = strlen($bodyContents);
                $response = $response->withHeader('Content-Length', (string) $size);

                $newBody = \Nyholm\Psr7\Stream::create($bodyContents);
                $response = $response->withBody($newBody);
            }
        }

        if (false === $connection->isKeepAlive()) {
            $response = $response->withHeader('Connection', 'close');
        } else {
            $response = $response->withHeader('Connection', 'keep-alive')
                ->withHeader('Keep-Alive', sprintf(
                    'timeout=%d, max=%d',
                    $this->config->keepAliveTimeout,
                    $this->config->keepAliveMaxRequests - $connection->getRequestCount(),
                ));
        }

        $httpResponse = $this->responseWriter->write($response);
        $written = $connection->write($httpResponse);

        if (false === $written) {
            $this->logger->warning('Failed to write response', [
                'remote' => $connection->getRemoteAddress(),
            ]);
        }
    }

    #[Override]
    public function sendError(ConnectionInterface $connection, int $status, string $message): void
    {
        $response = (new Response($status))
            ->withHeader('Content-Type', 'text/plain')
            ->withHeader('Connection', 'close');

        $response->getBody()->write($message);

        $httpResponse = $this->responseWriter->write($response);
        $connection->write($httpResponse);
    }
}
