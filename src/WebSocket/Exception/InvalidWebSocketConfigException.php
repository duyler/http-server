<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket\Exception;

use Duyler\HttpServer\Exception\InvalidConfigException;

class InvalidWebSocketConfigException extends InvalidConfigException
{
    protected string $errorCode = 'INVALID_WEBSOCKET_CONFIG';
}
