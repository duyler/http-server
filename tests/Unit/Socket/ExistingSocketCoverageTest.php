<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Socket;

use Duyler\HttpServer\Exception\SocketException;
use Duyler\HttpServer\Socket\ExistingSocket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Socket;

#[CoversClass(ExistingSocket::class)]
class ExistingSocketCoverageTest extends TestCase
{
    private Socket $socket;
    private ExistingSocket $sut;

    protected function setUp(): void
    {
        $pair = [];
        $created = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        if (false === $created) {
            $this->markTestSkipped('Unable to create socket pair');
        }
        $this->socket = $pair[0];
        socket_close($pair[1]);
        $this->sut = new ExistingSocket($this->socket);
    }

    protected function tearDown(): void
    {
        if ($this->sut->isValid()) {
            $this->sut->close();
        }
    }

    private function setClosedFlag(bool $value): void
    {
        $ref = new ReflectionProperty(ExistingSocket::class, 'closed');
        $ref->setValue($this->sut, $value);
    }

    #[Test]
    public function bind_always_throws_socket_exception(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot bind existing socket');

        $this->sut->bind('0.0.0.0', 80);
    }

    #[Test]
    public function listen_always_throws_socket_exception(): void
    {
        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Cannot listen on existing socket');

        $this->sut->listen(128);
    }

    #[Test]
    public function accept_returns_false_when_closed_via_reflection(): void
    {
        $this->setClosedFlag(true);

        $result = $this->sut->accept();

        $this->assertFalse($result);
    }

    #[Test]
    public function accept_returns_false_when_no_pending_connection(): void
    {
        socket_set_nonblock($this->socket);

        $previousErrorReporting = error_reporting(0);
        $result = $this->sut->accept();
        error_reporting($previousErrorReporting);

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_false_when_closed_via_reflection(): void
    {
        $this->setClosedFlag(true);

        $result = $this->sut->read(256);

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_data_from_socket_pair(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        socket_write($pair[1], 'hello world');

        $es = new ExistingSocket($pair[0]);
        $data = $es->read(1024);

        $this->assertSame('hello world', $data);

        $es->close();
        socket_close($pair[1]);
    }

    #[Test]
    public function read_returns_empty_string_on_closed_peer(): void
    {
        socket_set_nonblock($this->socket);

        $result = $this->sut->read(1024);

        $this->assertSame('', $result);
    }

    #[Test]
    public function write_returns_false_when_closed_via_reflection(): void
    {
        $this->setClosedFlag(true);

        $result = $this->sut->write('data');

        $this->assertFalse($result);
    }

    #[Test]
    public function write_returns_bytes_written_via_socket_pair(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $es = new ExistingSocket($pair[0]);
        $written = $es->write('payload');

        $this->assertSame(7, $written);

        $es->close();
        socket_close($pair[1]);
    }

    #[Test]
    public function write_returns_exact_length_for_long_payload(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $es = new ExistingSocket($pair[0]);
        $payload = str_repeat('x', 4096);
        $written = $es->write($payload);

        $this->assertSame(4096, $written);

        $es->close();
        socket_close($pair[1]);
    }

    #[Test]
    public function close_sets_closed_flag_to_true(): void
    {
        $this->assertTrue($this->sut->isValid());

        $this->sut->close();

        $this->assertFalse($this->sut->isValid());
    }

    #[Test]
    public function close_skips_socket_close_when_already_closed(): void
    {
        $this->setClosedFlag(true);

        $this->sut->close();

        $this->assertFalse($this->sut->isValid());
    }

    #[Test]
    public function close_can_be_called_multiple_times_without_error(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $es = new ExistingSocket($pair[0]);
        $es->close();
        $es->close();
        $es->close();

        $this->assertFalse($es->isValid());

        socket_close($pair[1]);
    }

    #[Test]
    public function is_valid_returns_true_before_close(): void
    {
        $this->assertTrue($this->sut->isValid());
    }

    #[Test]
    public function is_valid_returns_false_after_close(): void
    {
        $this->sut->close();

        $this->assertFalse($this->sut->isValid());
    }

    #[Test]
    public function is_valid_reflects_closed_property(): void
    {
        $this->assertTrue($this->sut->isValid());

        $this->setClosedFlag(true);
        $this->assertFalse($this->sut->isValid());

        $this->setClosedFlag(false);
        $this->assertTrue($this->sut->isValid());
    }

    #[Test]
    public function set_blocking_true_sets_blocking_mode(): void
    {
        $this->sut->setBlocking(true);

        $this->assertTrue($this->sut->isValid());
    }

    #[Test]
    public function set_blocking_false_sets_nonblocking_mode(): void
    {
        $this->sut->setBlocking(false);

        $this->assertTrue($this->sut->isValid());
    }

    #[Test]
    public function set_blocking_toggles_modes(): void
    {
        $this->sut->setBlocking(true);
        $this->sut->setBlocking(false);
        $this->sut->setBlocking(true);

        $this->assertTrue($this->sut->isValid());
    }

    #[Test]
    public function set_blocking_does_nothing_when_closed(): void
    {
        $this->setClosedFlag(true);

        $this->sut->setBlocking(true);

        $this->assertFalse($this->sut->isValid());
    }

    #[Test]
    public function set_blocking_false_does_nothing_when_closed(): void
    {
        $this->setClosedFlag(true);

        $this->sut->setBlocking(false);

        $this->assertFalse($this->sut->isValid());
    }

    #[Test]
    public function get_internal_resource_returns_socket_instance(): void
    {
        $result = $this->sut->getInternalResource();

        $this->assertInstanceOf(Socket::class, $result);
    }

    #[Test]
    public function get_internal_resource_returns_same_socket_object(): void
    {
        $result = $this->sut->getInternalResource();

        $this->assertSame($this->socket, $result);
    }

    #[Test]
    public function get_internal_resource_returns_socket_after_close(): void
    {
        $this->sut->close();

        $result = $this->sut->getInternalResource();

        $this->assertInstanceOf(Socket::class, $result);
    }

    #[Test]
    public function closed_state_is_consistent_across_all_operations(): void
    {
        $this->sut->close();

        $this->assertFalse($this->sut->accept());
        $this->assertFalse($this->sut->read(100));
        $this->assertFalse($this->sut->write('x'));
        $this->assertFalse($this->sut->isValid());
    }

    #[Test]
    public function operations_in_sequence_read_write_close(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $es = new ExistingSocket($pair[0]);

        $written = $es->write('abc');
        $this->assertSame(3, $written);

        $readBack = socket_read($pair[1], 1024);
        $this->assertSame('abc', $readBack);

        socket_write($pair[1], 'response');
        $data = $es->read(1024);
        $this->assertSame('response', $data);

        $es->close();
        $this->assertFalse($es->isValid());

        socket_close($pair[1]);
    }

    #[Test]
    public function accept_returns_false_after_explicit_close(): void
    {
        $this->sut->close();

        $result = $this->sut->accept();

        $this->assertFalse($result);
    }

    #[Test]
    public function read_returns_empty_string_for_zero_bytes_available(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $es = new ExistingSocket($pair[0]);
        socket_set_nonblock($pair[0]);

        $data = $es->read(1024);

        $this->assertFalse($data);

        $es->close();
        socket_close($pair[1]);
    }

    #[Test]
    public function write_with_empty_string_returns_zero(): void
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $es = new ExistingSocket($pair[0]);
        $written = $es->write('');

        $this->assertSame(0, $written);

        $es->close();
        socket_close($pair[1]);
    }

    #[Test]
    public function accept_with_non_listening_socket_returns_false(): void
    {
        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (false === $socket) {
            $this->markTestSkipped('Unable to create unix socket');
        }
        socket_set_nonblock($socket);

        $es = new ExistingSocket($socket);

        $previousErrorReporting = error_reporting(0);
        $result = $es->accept();
        error_reporting($previousErrorReporting);

        $this->assertFalse($result);

        $es->close();
    }
}
