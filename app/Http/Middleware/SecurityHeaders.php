<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Header keamanan dasar. Kamera dipakai monitoring fokus, jadi camera tidak dibatasi pada Permissions-Policy.
 * CSP ketat tidak dipasang agar Alpine.js tetap berfungsi.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), payment=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }

        return $response;
    }
}
