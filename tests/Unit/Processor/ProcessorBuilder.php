<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Processor;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Parser\RequestParser;
use Duyler\HttpServer\Parser\ResponseWriter;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Duyler\HttpServer\Processor\RequestQueue;
use Duyler\HttpServer\Processor\ResponseSender;
use Duyler\HttpServer\Upload\TempFileManager;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;

final class ProcessorBuilder
{
    private ServerConfig $config;

    public function __construct()
    {
        $this->config = new ServerConfig();
    }

    public function withConfig(ServerConfig $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function build(): HttpRequestProcessor
    {
        $httpParser = new HttpParser();
        $psr17Factory = new Psr17Factory();
        $tempFileManager = new TempFileManager();
        $requestParser = new RequestParser($httpParser, $psr17Factory, $tempFileManager);
        $responseWriter = new ResponseWriter();
        $connectionPool = new ConnectionPool(100);
        $metrics = new ServerMetrics();

        return new HttpRequestProcessor(
            $this->config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $connectionPool,
            $metrics,
            $tempFileManager,
            new RequestQueue(),
            new ResponseSender($this->config, $responseWriter),
            null,
            null,
            new NullLogger(),
        );
    }
}
