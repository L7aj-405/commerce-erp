<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `signed` check for MCP document links, restricted to the query
 * parameters the link was signed with.
 *
 * MCP clients rewrite links before the user opens them — ChatGPT appends
 * `utm_source=chatgpt.com` — and the stock middleware signs the whole query
 * string, so any added parameter made a valid link fail as "Invalid
 * signature". Only the signed parameters are verified here (still the native
 * HMAC over the absolute URL with APP_KEY); anything else is ignored, which
 * is safe because the controller reads nothing but the path id and the
 * signed `connection`. Removing or altering a signed parameter still fails.
 */
final class ValidateMcpDocumentSignature
{
    /**
     * Every parameter the link is signed with. A new functional parameter
     * must be added here — anything not listed is ignored, not verified.
     *
     * @var list<string>
     */
    private const SIGNED_PARAMETERS = ['connection', 'expires'];

    public function handle(Request $request, Closure $next): Response
    {
        $ignore = fn (string $parameter): bool => ! in_array($parameter, self::SIGNED_PARAMETERS, true);

        abort_unless(URL::hasCorrectSignature($request, true, $ignore), 403, 'Invalid signature.');
        abort_unless(URL::signatureHasNotExpired($request), 403, 'This document link has expired. Request a new one from your assistant.');

        return $next($request);
    }
}
