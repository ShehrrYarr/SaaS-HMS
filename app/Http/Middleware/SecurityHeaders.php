<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening on every response: no framing by other sites (clickjacking),
 * no MIME sniffing, no full URLs leaked in the Referer header, and no PHP version banner.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        header_remove('X-Powered-By');
        $headers->remove('X-Powered-By');

        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        // Camera and microphone stay available for telemedicine (the Jitsi frame asks for them).
        $headers->set('Permissions-Policy', 'geolocation=(), payment=(), usb=(), serial=(), bluetooth=()', false);
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        return $response;
    }
}
