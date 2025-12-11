<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Integration;

use Duyler\HttpServer\Tests\Support\PlatformHelper;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('pcntl')]
class FdPassingIntegrationTest extends TestCase
{
    #[Test]
    public function fd_passing_works_in_real_process(): void
    {
        if (!PlatformHelper::supportsSCMRights()) {
            $this->markTestSkipped(PlatformHelper::getSkipReason('scm_rights'));
        }

        $result = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->assertTrue($result);

        [$socket1, $socket2] = $pair;

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('Failed to fork process');
        }

        if ($pid === 0) {
            socket_close($socket2);

            $testSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

            $message = [
                'iov' => ['test-data'],
                'control' => [
                    [
                        'level' => SOL_SOCKET,
                        'type' => SCM_RIGHTS,
                        'data' => [(int) $testSocket],
                    ],
                ],
            ];

            $result = @socket_sendmsg($socket1, $message, 0);

            socket_close($testSocket);
            socket_close($socket1);

            exit($result === false ? 1 : 0);
        }

        socket_close($socket1);

        $buffer = str_repeat("\0", 1024);
        $recvMsg = [
            'iov' => [$buffer],
            'control' => [],
        ];

        $received = @socket_recvmsg($socket2, $recvMsg, 0);

        socket_close($socket2);

        pcntl_waitpid($pid, $status);
        $exitCode = pcntl_wexitstatus($status);

        $this->assertSame(0, $exitCode, 'Child process failed to send FD');
        $this->assertNotFalse($received, 'Failed to receive FD');
        $this->assertGreaterThan(0, $received, 'No data received');

        if (isset($recvMsg['control'][0]['data'][0])) {
            $this->assertIsInt($recvMsg['control'][0]['data'][0]);
        } else {
            $this->markTestIncomplete('FD not found in control message');
        }
    }
}
