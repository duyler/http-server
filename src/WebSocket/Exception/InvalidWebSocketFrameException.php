<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket\Exception;

use Duyler\HttpServer\Exception\HttpServerException;

final class InvalidWebSocketFrameException extends HttpServerException
{
    protected string $errorCode = 'INVALID_WEBSOCKET_FRAME';
}
