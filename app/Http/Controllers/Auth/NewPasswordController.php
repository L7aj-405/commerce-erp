<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 1.1 §3 — "forgot password" step 2. The Password Broker itself
 * enforces the security-relevant token behaviour: it checks the token against
 * the hashed value in `password_reset_tokens`, rejects it once
 * `config('auth.passwords.users.expire')` minutes have passed, and deletes
 * the row on success — so a token is both time-limited and single-use without
 * any bespoke logic here.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker()->reset(
            $data,
            function (User $user) use ($request, $audit): void {
                $user->forceFill([
                    'password' => $request->string('password')->toString(),
                    'remember_token' => Str::random(60),
                ])->save();

                // §8 — a password reset invalidates every OTHER active
                // session for this account. The requester has no session of
                // their own yet at this point (they are an anonymous holder
                // of a valid token), so every row for this user is stale.
                DB::table('sessions')->where('user_id', $user->getKey())->delete();

                event(new PasswordReset($user));

                $audit->record('auth.password_reset', $user, $user->activeOrganization, auditable: $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Not an enumeration concern at this stage — reaching this page
            // at all already required a token that was emailed to a real
            // address; an invalid/expired/reused token is ordinary, expected
            // feedback (§3), never the reset token or password themselves.
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return redirect()->route('login')->with('status', 'Mot de passe réinitialisé. Vous pouvez vous connecter.');
    }
}
