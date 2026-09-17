<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 1.1 §3 — "forgot password" step 1. Uses Laravel's own Password
 * Broker/token architecture (expiring, single-use tokens in
 * `password_reset_tokens`) rather than a bespoke mechanism. The broker sends
 * through `Illuminate\Auth\Notifications\ResetPassword`, which — like every
 * other Auth notification in this app — goes out via the notification
 * system's default ('mail') channel, i.e. the PLATFORM mailer
 * (config/mail.php), never an organization's own SMTP
 * ({@see \App\Services\OrganizationOutboundMailService} is a distinct,
 * explicitly-invoked path used only for document email).
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);

        // The broker's return status (link sent / no such user / throttled)
        // is intentionally never inspected here — the response to the
        // browser must be identical whether or not the account exists (§3
        // anti-enumeration). Whatever happened, happened out of band by mail.
        Password::broker()->sendResetLink($data);

        return back()->with(
            'status',
            'Si un compte existe pour cette adresse, un lien de réinitialisation vient de lui être envoyé.',
        );
    }
}
