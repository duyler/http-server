<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket\Enum;

enum CloseCode: int
{
    case NORMAL = 1000;
    case GOING_AWAY = 1001;
    case PROTOCOL_ERROR = 1002;
    case UNSUPPORTED_DATA = 1003;
    case INVALID_FRAME_PAYLOAD = 1007;
    case POLICY_VIOLATION = 1008;
    case MESSAGE_TOO_BIG = 1009;
    case INTERNAL_ERROR = 1011;
}
