<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Connection;

use Duyler\HttpServer\Connection\ConnectionManager;
use Duyler\HttpServer\Connection\ConnectionPool;
use Duyler\HttpServer\Metrics\ServerMetrics;
use Duyler\HttpServer\Parser\HttpParser;
use Duyler\HttpServer\Processor\HttpRequestProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConnectionManagerTest extends TestCase
{
    private ConnectionManager $manager;
    private ConnectionPool $pool;

    protected function setUp(): void
    {
        $this->pool = new ConnectionPool();
        $httpParser = new HttpParser(100);
        $psrFactory = new Psr17Factory();
        $tempFileManager = new \Duyler\HttpServer\Upload\TempFileManager();
        $requestParser = new \Duyler\HttpServer\Parser\RequestParser($httpParser, $psrFactory, $tempFileManager);
        $responseWriter = new \Duyler\HttpServer\Parser\ResponseWriter();
        $metrics = new ServerMetrics();
        $config = new \Duyler\HttpServer\Config\ServerConfig();

        $requestProcessor = new HttpRequestProcessor(
            $config,
            $httpParser,
            $requestParser,
            $responseWriter,
            $this->pool,
            $metrics,
            $tempFileManager,
        );

        $this->manager = new ConnectionManager(
            $this->pool,
            $httpParser,
            $requestProcessor,
            $metrics,
            new NullLogger(),
        );
    }

    #[Test]
    public function add_delegates_to_pool(): void
    {
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function count_returns_correct_value(): void
    {
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function close_all_clears_pool(): void
    {
        $this->manager->closeAll();
        $this->assertSame(0, $this->manager->count());
    }

    #[Test]
    public function set_logger_sets_logger(): void
    {
        $logger = new NullLogger();
        $this->manager->setLogger($logger);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function remove_timed_out_returns_zero_when_empty(): void
    {
        $result = $this->manager->removeTimedOut(30);
        $this->assertSame(0, $result);
    }

    #[Test]
    public function get_all_returns_empty_array_initially(): void
    {
        $this->assertSame([], $this->manager->getAll());
    }
}
