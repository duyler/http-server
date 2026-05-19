<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('pcntl')]
class FdPassingIntegrationTest extends TestCase
{
    #[Test]
    public function scm_rights_api_is_available(): void
    {
        $this->assertTrue(
            defined('SCM_RIGHTS'),
            'SCM_RIGHTS constant should be defined on Linux',
        );

        $this->assertTrue(
            function_exists('socket_sendmsg'),
            'socket_sendmsg should be available',
        );

        $this->assertTrue(
            function_exists('socket_recvmsg'),
            'socket_recvmsg should be available',
        );
    }

    #[Test]
    public function fd_can_be_sent_via_unix_socket_pair(): void
    {
        if (!defined('SCM_RIGHTS')) {
            $this->fail('SCM_RIGHTS not defined');
        }

        $pair = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($result, 'Failed to create socket pair');

        [$sock1, $sock2] = $pair;

        $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($testSocket, 'Failed to create test socket');

        $message = [
            'iov' => ['fd-transfer'],
            'control' => [
                [
                    'level' => SOL_SOCKET,
                    'type' => SCM_RIGHTS,
                    'data' => [$testSocket],
                ],
            ],
        ];

        $previousErrorReporting = error_reporting(0);
        $sent = socket_sendmsg($sock1, $message, 0);
        error_reporting($previousErrorReporting);

        $this->assertNotFalse($sent, 'socket_sendmsg should succeed with SCM_RIGHTS');

        $buffer = str_repeat("\0", 1024);
        $recvMsg = [
            'iov' => [$buffer],
            'control' => [],
        ];

        socket_set_nonblock($sock2);
        $previousErrorReporting = error_reporting(0);
        $received = socket_recvmsg($sock2, $recvMsg, 0);
        error_reporting($previousErrorReporting);

        if (false !== $received) {
            $this->assertGreaterThan(0, $received, 'Should receive data');

            if (isset($recvMsg['control'][0]['data'][0])) {
                $recvFd = $recvMsg['control'][0]['data'][0];
                $this->assertTrue(
                    is_resource($recvFd) || $recvFd instanceof \Socket,
                    'Received FD should be a Socket or resource',
                );
            }
        }

        socket_close($sock1);
        socket_close($sock2);
        socket_close($testSocket);
    }

    #[Test]
    public function socket_create_pair_works(): void
    {
        $pair = [];
        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);

        $this->assertTrue($result);
        $this->assertCount(2, $pair);

        socket_write($pair[0], 'hello');
        $data = socket_read($pair[1], 1024);

        $this->assertSame('hello', $data);

        socket_close($pair[0]);
        socket_close($pair[1]);
    }
}

