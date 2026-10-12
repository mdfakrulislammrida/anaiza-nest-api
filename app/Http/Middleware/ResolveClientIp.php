<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Lets the shop read the visitor's address from one named header (CF-Connecting-IP, True-Client-IP, X-Real-IP) when the CDN in
 * front of it sets that instead of X-Forwarded-For. It only ever applies when CLIENT_IP_HEADER is set AND the connection comes
 * from an address listed in TRUSTED_PROXIES, so a visitor who reaches the origin directly cannot choose their own address.
 * With nothing configured (the default) it does nothing.
 */
class ResolveClientIp
{
    public function handle(Request $request, Closure $next)
    {
        $header = config('trustedproxy.client_ip_header');
        $trusted = config('trustedproxy.proxies');

        if ($header !== null && $trusted !== null) {
            $peer = (string) $request->server->get('REMOTE_ADDR');
            $value = trim((string) $request->headers->get($header));

            // A header can carry a list ("client, proxy"); the first entry is the visitor.
            $candidate = trim(explode(',', $value)[0] ?? '');

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) && self::isTrusted($peer, $trusted)) {
                $request->server->set('REMOTE_ADDR', $candidate);
            }
        }

        return $next($request);
    }

    /**
     * @param  string|array<int, string>  $trusted
     */
    private static function isTrusted(string $peer, string|array $trusted): bool
    {
        if ($trusted === '*') {
            return true;
        }

        $list = array_map(fn (string $item): string => $item === 'REMOTE_ADDR' ? $peer : $item, (array) $trusted);

        return $peer !== '' && IpUtils::checkIp($peer, $list);
    }
}
