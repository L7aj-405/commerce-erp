<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Mon profil" — the authenticated user's OWN name/email. This is personal
 * account state, never organization membership/role/permissions (those stay
 * in UsersAccessController / OrganizationMembershipController).
 *
 * Changing the email is treated as a security-sensitive operation: it
 * requires re-entering the current password (same immediate-request pattern
 * as TwoFactorAuthenticationController::confirmPassword — the password is
 * part of THIS request, not a stale session timestamp), resets
 * `email_verified_at`, and re-sends the exact same verification notification
 * a new registration goes through. MustVerifyEmail is never weakened: this
 * route sits behind the same `verified` gate as every other business page,
 * so the very next request after an email change is routed to the
 * verification-notice screen until the new address is confirmed.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Profile', [
            'user' => $user->only(['name', 'email']),
            'emailVerified' => $user->hasVerifiedEmail(),
            'status' => session('status'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->getKey())],
            'current_password' => ['nullable', 'string'],
        ], [
            'name.required' => 'Indiquez votre nom.',
            'email.required' => 'Indiquez votre adresse email.',
            'email.email' => 'Cette adresse email n’est pas valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
        ]);

        $emailChanged = $data['email'] !== $user->email;

        if ($emailChanged) {
            if (blank($data['current_password'] ?? null)) {
                throw ValidationException::withMessages([
                    'current_password' => 'Votre mot de passe actuel est requis pour changer d’adresse email.',
                ]);
            }

            if (! Auth::guard('web')->validate(['email' => $user->email, 'password' => $data['current_password']])) {
                throw ValidationException::withMessages(['current_password' => 'Mot de passe incorrect.']);
            }
        }

        $oldValues = ['name' => $user->name, 'email' => $user->email];

        $user->name = $data['name'];

        if ($emailChanged) {
            $user->email = $data['email'];
            $user->email_verified_at = null;
        }

        $user->save();

        $audit->record(
            $emailChanged ? 'account.email_changed' : 'account.profile_updated',
            $user,
            $user->activeOrganization,
            auditable: $user,
            oldValues: $oldValues,
            newValues: ['name' => $user->name, 'email' => $user->email],
        );

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();

            return redirect()->route('account.profile.edit')->with(
                'success',
                'Profil mis à jour. Vérifiez votre nouvelle adresse email pour la confirmer.',
            );
        }

        return redirect()->route('account.profile.edit')->with('success', 'Profil mis à jour.');
    }
}
