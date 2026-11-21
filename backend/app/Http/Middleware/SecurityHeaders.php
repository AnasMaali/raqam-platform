<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every answer of the back end carries (the panel, the API, the preview page, the error pages, and the
 * answers made before the application's code runs: it is the outermost middleware).
 * An endpoint that chose its own value for one of them (the preview page's stricter policy) keeps it.
 *
 * There is no full Content-Security-Policy for the panel: Filament's Alpine needs inline evaluation. What is set
 * here is the part that costs nothing: nobody may frame the panel (clickjacking on "publish" or "delete"),
 * and nobody may change what a relative address means or where a form posts to.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP names itself and its version (X-Powered-By: PHP/8.x) unless its ini says expose_php = Off: taken off here, so
        // no answer depends on how the server's PHP was set up (QA-8).
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // No page of another origin may load an answer as a picture, a script or a frame (QA-8). None needs to: the
            // browser never calls the API, and the media are files the web server sends, outside PHP.
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $csp = (string) $response->headers->get('Content-Security-Policy');
        if (! str_contains($csp, 'frame-ancestors')) {
            $response->headers->set('Content-Security-Policy', trim($csp.'; '.implode('; ', ["frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'", "object-src 'none'"]), '; '));
        }

        if ($request->isSecure() && ! $response->headers->has('Strict-Transport-Security')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
