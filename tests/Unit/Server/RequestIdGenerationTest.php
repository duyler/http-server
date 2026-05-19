<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Server;

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Server;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RequestIdGenerationTest extends TestCase
{
    private ?Server $server = null;

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->server) {
            $this->server->stop();
            $this->server->reset();
        }
        parent::tearDown();
    }
    #[Test]
    public function it_generates_sequential_request_ids(): void
    {
        $config = new ServerConfig(port: 18085);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $id1 = $requestProcessor->generateRequestId();
        $id2 = $requestProcessor->generateRequestId();
        $id3 = $requestProcessor->generateRequestId();

        self::assertSame('req_0', $id1);
        self::assertSame('req_1', $id2);
        self::assertSame('req_2', $id3);
    }

    #[Test]
    public function it_generates_unique_ids_for_each_request(): void
    {
        $config = new ServerConfig(port: 18086);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $requestProcessor->generateRequestId();
        }

        $uniqueIds = array_unique($ids);

        self::assertCount(100, $uniqueIds);
    }

    #[Test]
    public function it_prefixes_ids_with_req(): void
    {
        $config = new ServerConfig(port: 18087);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        for ($i = 0; $i < 10; $i++) {
            $id = $requestProcessor->generateRequestId();
            self::assertStringStartsWith('req_', $id);
        }
    }

    #[Test]
    public function it_starts_counter_from_zero(): void
    {
        $config = new ServerConfig(port: 18088);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $id = $requestProcessor->generateRequestId();

        self::assertSame('req_0', $id);
    }

    #[Test]
    public function it_increments_counter_after_each_request(): void
    {
        $config = new ServerConfig(port: 18089);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $counterProperty = $rpReflection->getProperty('requestIdCounter');
        $counterProperty->setAccessible(true);

        self::assertSame(0, $counterProperty->getValue($requestProcessor));

        $requestProcessor->generateRequestId();
        self::assertSame(1, $counterProperty->getValue($requestProcessor));

        $requestProcessor->generateRequestId();
        self::assertSame(2, $counterProperty->getValue($requestProcessor));

        $requestProcessor->generateRequestId();
        self::assertSame(3, $counterProperty->getValue($requestProcessor));
    }

    #[Test]
    public function it_formats_large_numbers_correctly(): void
    {
        $config = new ServerConfig(port: 18090);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $counterProperty = $rpReflection->getProperty('requestIdCounter');
        $counterProperty->setAccessible(true);

        $counterProperty->setValue($requestProcessor, 999999);

        $id = $requestProcessor->generateRequestId();

        self::assertSame('req_999999', $id);
    }

    #[Test]
    public function it_resets_counter_on_server_reset(): void
    {
        $config = new ServerConfig(port: 18091);
        $this->server = new Server($config);

        $reflection = new ReflectionClass($this->server);
        $requestProcessorProperty = $reflection->getProperty('requestProcessor');
        $requestProcessorProperty->setAccessible(true);
        $requestProcessor = $requestProcessorProperty->getValue($this->server);

        $rpReflection = new ReflectionClass($requestProcessor);
        $counterProperty = $rpReflection->getProperty('requestIdCounter');
        $counterProperty->setAccessible(true);

        for ($i = 0; $i < 50; $i++) {
            $requestProcessor->generateRequestId();
        }

        self::assertSame(50, $counterProperty->getValue($requestProcessor));

        $this->server->reset();

        self::assertSame(0, $counterProperty->getValue($requestProcessor));

        $id = $requestProcessor->generateRequestId();
        self::assertSame('req_0', $id);
    }
}
