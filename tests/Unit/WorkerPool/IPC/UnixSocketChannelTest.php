<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\WorkerPool\IPC;

use Duyler\HttpServer\WorkerPool\Exception\IPCException;
use Duyler\HttpServer\WorkerPool\IPC\Message;
use Duyler\HttpServer\WorkerPool\IPC\UnixSocketChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UnixSocketChannelTest extends TestCase
{
    private string $socketPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->socketPath = sys_get_temp_dir() . '/test_socket_' . uniqid() . '.sock';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->socketPath)) {
            @unlink($this->socketPath);
        }
    }

    #[Test]
    public function creates_server_socket(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);

        $this->assertTrue($server->connect());
        $this->assertTrue($server->isConnected());
        $this->assertNotNull($server->getSocket());

        $server->close();
    }

    #[Test]
    public function creates_client_socket(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, isServer: false);

        $this->assertTrue($client->connect());
        $this->assertTrue($client->isConnected());

        $client->close();
        $server->close();
    }

    #[Test]
    public function server_accepts_client_connection(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, isServer: false);
        $client->connect();

        usleep(10000);

        $clientSocket = $server->accept();
        $this->assertNotNull($clientSocket);

        socket_close($clientSocket);
        $client->close();
        $server->close();
    }

    #[Test]
    public function sends_and_receives_message(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, isServer: false);
        $client->connect();

        usleep(10000);

        $message = Message::workerReady(42);
        $this->assertTrue($client->send($message));

        usleep(10000);

        $clientSocket = $server->accept();
        $this->assertNotNull($clientSocket);

        socket_close($clientSocket);
        $client->close();
        $server->close();
    }

    #[Test]
    public function throws_on_send_without_connection(): void
    {
        $channel = new UnixSocketChannel($this->socketPath);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Socket is not connected');

        $channel->send(Message::shutdown());
    }

    #[Test]
    public function throws_on_receive_without_connection(): void
    {
        $channel = new UnixSocketChannel($this->socketPath);

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Socket is not connected');

        $channel->receive();
    }

    #[Test]
    public function throws_on_accept_from_non_server(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, isServer: false);
        $client->connect();

        $this->expectException(IPCException::class);
        $this->expectExceptionMessage('Cannot accept on non-server socket');

        $client->accept();

        $client->close();
        $server->close();
    }

    #[Test]
    public function closes_socket_properly(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $this->assertTrue($server->isConnected());

        $server->close();

        $this->assertFalse($server->isConnected());
        $this->assertNull($server->getSocket());
    }

    #[Test]
    public function removes_socket_file_on_server_close(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $this->assertFileExists($this->socketPath);

        $server->close();

        $this->assertFileDoesNotExist($this->socketPath);
    }

    #[Test]
    public function returns_null_when_no_client_to_accept(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $clientSocket = $server->accept();

        $this->assertNull($clientSocket);

        $server->close();
    }

    #[Test]
    public function returns_null_when_no_message_to_receive(): void
    {
        $server = new UnixSocketChannel($this->socketPath, isServer: true);
        $server->connect();

        $client = new UnixSocketChannel($this->socketPath, isServer: false);
        $client->connect();

        usleep(10000);

        $message = $client->receive();

        $this->assertNull($message);

        $client->close();
        $server->close();
    }
}
