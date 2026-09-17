<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Mot de passe" (Account Settings > Security). JSON endpoint, matching the
 * fetch-based pattern the rest of the Security page already uses for 2FA
 * (see TwoFactorAuthenticationController) rather than a separate Inertia
 * form. Re-authenticates with the CURRENT password as part of this exact
 * request — the same immediate-request pattern TwoFactorAuthenticationController
 * uses for disable/regenerate — and, on success, rotates the current session
 * id and invalidates every OTHER active session, mirroring what a
 * broker-driven password reset already does (see NewPasswordController §8).
 */
class PasswordController extends Controller
{
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.required' => 'Indiquez votre mot de passe actuel.',
            'password.required' => 'Choisissez un nouveau mot de passe.',
            'password.confirmed' => 'La confirmation ne correspond pas au nouveau mot de passe.',
            'password.min' => 'Le mot de passe doit contenir au moins :min caractères.',
        ]);

        if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $data['current_password']])) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // §8 — rotate this session's id, then drop every other one: a
        // password change ends every session but the one making the change.
        $request->session()->regenerate();
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $audit->record('auth.password_changed', $user, $user->activeOrganization, auditable: $user);

        return response()->json(['message' => 'Mot de passe modifié. Vos autres sessions ont été déconnectées.']);
    }
}
