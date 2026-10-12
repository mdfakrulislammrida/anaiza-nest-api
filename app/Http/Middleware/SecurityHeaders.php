<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers every browser-facing answer from the API should carry.
 *
 * The API answers JSON, the Filament admin and a few small pages (unsubscribe, feeds). JSON gets a CSP that forbids
 * everything; the admin (which needs inline scripts and styles for Livewire) is only protected from being framed.
 * HSTS is off until HSTS_ENABLED=true, because it cannot be taken back quickly once a browser has seen it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // Not advertised: the PHP version helps nobody but an attacker.
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->is('api/*') || $request->is('feeds/*')) {
            $headers->set('X-Frame-Options', 'DENY');
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        } else {
            // The admin and the small HTML pages may only be framed by this same site.
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        }

        if (config('abuse.hsts_enabled') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.(int) config('abuse.hsts_max_age', 15552000));
        }

        return $response;
    }
}
