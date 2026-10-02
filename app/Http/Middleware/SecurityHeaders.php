<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralized production HTTP security headers.
 *
 * PDF Studio renders generated PDF previews through a browser Blob URL:
 * blob:https://your-domain/...
 *
 * Because of that, CSP must explicitly allow blob: as a frame source.
 * frame-ancestors remains locked down so the ERP itself cannot be embedded
 * by third-party websites.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! app()->environment('production')) {
            return $response;
        }

        $response->headers->set(
            'X-Content-Type-Options',
            'nosniff'
        );

        $response->headers->set(
            'X-Frame-Options',
            'DENY'
        );

        $response->headers->set(
            'Referrer-Policy',
            'strict-origin-when-cross-origin'
        );

        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=()'
        );

        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        $response->headers->set(
            'Content-Security-Policy',
            implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' https: data:",
                "font-src 'self' data:",
                "connect-src 'self'",

                // PDF Studio preview:
                // React fetches the generated PDF, creates a Blob URL,
                // then loads that blob inside an iframe.
                "frame-src 'self' blob:",

                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",

                // Prevent external websites from framing the ERP itself.
                "frame-ancestors 'none'",
            ])
        );

        return $response;
    }
}