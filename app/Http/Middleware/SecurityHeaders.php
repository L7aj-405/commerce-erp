<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Centralized production HTTP security headers (§G). Scoped to
 * `app()->environment('production')` only — Vite's local dev server (HMR
 * over a websocket, `@viteReactRefresh`'s eval-ish runtime) would violate a
 * CSP this strict, and there is no value in fighting that locally.
 *
 * CSP was built from what this app's production build actually emits, not a
 * generic template:
 *  - script-src 'self' only — @vite/@inertiaHead/@inertia render a
 *    module-script tag and a data-page attribute, never an inline <script>;
 *    no CDN script is loaded anywhere in resources/js or resources/views.
 *  - style-src needs 'unsafe-inline' — React's `style={{...}}` (dynamic
 *    invoice accent colors, POS grid column counts, the 72mm receipt layout)
 *    renders as inline style="" attributes in real, currently-shipping pages
 *    (see Documents/Invoices/Show.tsx, Pos/PosCatalogue.tsx, Pos/PosReceipt.tsx).
 *    Nonce-based inline styles aren't practical for values computed at
 *    render time without a larger refactor, so this is a deliberate,
 *    documented exception — not a blanket "give up on CSP".
 *  - img-src allows https: broadly — WooCommerce product images are
 *    tenant-controlled URLs on arbitrary merchant domains (see the
 *    variant-image sync work); there is no fixed set of hosts to allow-list.
 *  - No unsafe-eval, no wildcard script host, no remote font/style CDN
 *    (this app is 100% self-hosted assets).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! app()->environment('production')) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=(), usb=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' https: data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        return $response;
    }
}
