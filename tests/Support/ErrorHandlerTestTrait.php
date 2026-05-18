<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Support;

use Duyler\HttpServer\ErrorHandler;
use Duyler\HttpServer\Server;
use Throwable;

trait ErrorHandlerTestTrait
{
    private ?Server $testServer = null;

    protected function createTestServer(array $config = []): Server
    {
        $this->testServer = new Server(new \Duyler\HttpServer\Config\ServerConfig(...$config));
        return $this->testServer;
    }

    protected function setTestServer(Server $server): void
    {
        $this->testServer = $server;
    }

    protected function resetErrorHandlerState(): void
    {
        if (null !== $this->testServer) {
            try {
                $this->testServer->reset();
            } catch (Throwable) {
            }
            $this->testServer = null;
        }

        try {
            ErrorHandler::reset();
        } catch (Throwable) {
        }

        error_clear_last();
    }
}
