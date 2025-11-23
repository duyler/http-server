<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket\Enum;

enum ConnectionState
{
    case CONNECTING;
    case OPEN;
    case CLOSING;
    case CLOSED;
}
