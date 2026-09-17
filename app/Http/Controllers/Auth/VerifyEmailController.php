<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * EmailVerificationRequest validates the signed URL's id+hash against the
     * currently authenticated user before this ever runs — a link minted for
     * one account can't verify another (Sprint 1.1 §6 audit: confirmed —
     * `EmailVerificationRequest::authorize()` compares the route's `id` to
     * `$this->user()->getKey()` AND the `hash` to
     * `sha1($this->user()->getEmailForVerification())`; the `signed` +
     * `throttle:6,1` middleware on the route add tamper- and
     * brute-force-resistance on top). Expiry comes from the signed URL itself
     * (default 60 minutes — see `Illuminate\Auth\Notifications\VerifyEmail`).
     */
    public function __invoke(EmailVerificationRequest $request, AuditLogger $audit): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('platform.index');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
            $audit->record('email.verified', $request->user(), $request->user()->activeOrganization, auditable: $request->user());

            // Defense in depth: rotate the session id across this state
            // transition. Not required to prevent an authentication bypass
            // (the user was already authenticated in this exact session
            // before and after verifying), but it costs nothing and matches
            // this app's pattern of never carrying a session id across a
            // sensitive account-state change.
            $request->session()->regenerate();
        }

        return redirect()->route('platform.index')->with('success', 'Adresse email vérifiée.');
    }
}
