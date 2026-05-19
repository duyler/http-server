<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Util;

use Psr\Http\Message\ServerRequestInterface;

final readonly class ClientIpResolver
{
    /**
     * @param list<string> $trustedProxies IP addresses of trusted proxy servers
     */
    public static function resolve(ServerRequestInterface $request, array $trustedProxies = []): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? null;

        assert(null === $remoteAddr || is_string($remoteAddr));

        if (is_string($remoteAddr) && in_array($remoteAddr, $trustedProxies, true)) {
            if (isset($serverParams['HTTP_X_FORWARDED_FOR']) && is_string($serverParams['HTTP_X_FORWARDED_FOR'])) {
                $ips = array_map('trim', explode(',', $serverParams['HTTP_X_FORWARDED_FOR']));

                for ($i = count($ips) - 1; $i >= 0; $i--) {
                    if (false !== filter_var($ips[$i], FILTER_VALIDATE_IP) && !in_array($ips[$i], $trustedProxies, true)) {
                        return $ips[$i];
                    }
                }
            }

            if (isset($serverParams['HTTP_X_REAL_IP']) && is_string($serverParams['HTTP_X_REAL_IP'])) {
                $ip = $serverParams['HTTP_X_REAL_IP'];
                if (false !== filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (is_string($remoteAddr) && '' !== $remoteAddr) {
            return $remoteAddr;
        }

        return 'unknown';
    }
}
