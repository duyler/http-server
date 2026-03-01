<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Exception;

use Socket;

use function socket_last_error;
use function socket_strerror;

final class SocketException extends HttpServerException
{
    protected string $errorCode = 'SOCKET_ERROR';

    public static function fromLastError(?Socket $socket = null): self
    {
        $errorCode = $socket !== null ? socket_last_error($socket) : socket_last_error();
        $errorMsg = socket_strerror($errorCode);

        return new self(
            message: $errorMsg,
            code: $errorCode,
            context: ['socket_error' => $errorCode],
        );
    }
}
