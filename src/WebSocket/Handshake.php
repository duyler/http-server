<?php

declare(strict_types=1);

namespace Duyler\HttpServer\WebSocket;

use Duyler\HttpServer\Security\AuditLoggerInterface;
use Duyler\HttpServer\Util\ClientIpResolver;
use Psr\Http\Message\ServerRequestInterface;

final class Handshake
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public static function isWebSocketRequest(ServerRequestInterface $request): bool
    {
        if (false === $request->hasHeader('Upgrade')) {
            return false;
        }

        $upgrade = strtolower($request->getHeaderLine('Upgrade'));
        if ('websocket' !== $upgrade) {
            return false;
        }

        if (false === $request->hasHeader('Connection')) {
            return false;
        }

        $connection = strtolower($request->getHeaderLine('Connection'));
        if (false === str_contains($connection, 'upgrade')) {
            return false;
        }

        if (false === $request->hasHeader('Sec-WebSocket-Key')) {
            return false;
        }

        if (false === $request->hasHeader('Sec-WebSocket-Version')) {
            return false;
        }

        $version = $request->getHeaderLine('Sec-WebSocket-Version');
        if ('13' !== $version) {
            return false;
        }

        return true;
    }

    public static function generateAccept(string $key): string
    {
        return base64_encode(sha1($key . self::GUID, true));
    }

    public static function createResponse(ServerRequestInterface $request, WebSocketConfig $config): string
    {
        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        $accept = self::generateAccept($key);

        $response = "HTTP/1.1 101 Switching Protocols\r\n";
        $response .= "Upgrade: websocket\r\n";
        $response .= "Connection: Upgrade\r\n";
        $response .= "Sec-WebSocket-Accept: {$accept}\r\n";

        if ($request->hasHeader('Sec-WebSocket-Protocol')) {
            $requestedProtocols = array_map(
                'trim',
                explode(',', $request->getHeaderLine('Sec-WebSocket-Protocol')),
            );

            $selectedProtocol = self::selectProtocol($requestedProtocols, $config->subProtocols);

            if (null !== $selectedProtocol) {
                $response .= "Sec-WebSocket-Protocol: {$selectedProtocol}\r\n";
            }
        }

        $response .= "\r\n";

        return $response;
    }

    public static function validateOrigin(
        ServerRequestInterface $request,
        WebSocketConfig $config,
        ?AuditLoggerInterface $auditLogger = null,
    ): bool {
        $origin = $request->getHeaderLine('Origin');
        $clientIp = ClientIpResolver::resolve($request);

        if (false === $config->validateOrigin) {
            return true;
        }

        if (false === $request->hasHeader('Origin')) {
            $auditLogger?->logInvalidOrigin($clientIp, $origin);
            return false;
        }

        if (in_array($origin, $config->allowedOrigins, true)) {
            $auditLogger?->logWebSocketConnection($clientIp, $origin, true);
            return true;
        }

        $auditLogger?->logWebSocketConnection($clientIp, $origin, false);
        return false;
    }

    public static function isInsecureConfig(WebSocketConfig $config): bool
    {
        if ($config->validateOrigin) {
            return false;
        }

        if (in_array('*', $config->allowedOrigins, true)) {
            return true;
        }

        if (0 === count($config->allowedOrigins)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string> $requestedProtocols
     * @param array<string> $supportedProtocols
     */
    private static function selectProtocol(array $requestedProtocols, array $supportedProtocols): ?string
    {
        foreach ($requestedProtocols as $protocol) {
            if (in_array($protocol, $supportedProtocols, true)) {
                return $protocol;
            }
        }

        return null;
    }
}
