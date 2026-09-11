<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public (unauthenticated-reachable) acceptance of an invite-by-email link.
 * The random token in the URL IS the authorization — this intentionally sits
 * outside the `auth` middleware group, exactly like /register and /login.
 */
class InvitationAcceptController extends Controller
{
    public function show(string $token, InvitationService $invitations): Response
    {
        $invitation = $invitations->findByToken($token);

        if (! $invitation || ! $invitation->isPending()) {
            return Inertia::render('Auth/AcceptInvitation', ['status' => 'invalid']);
        }

        $invitation->loadMissing(['organization:id,name', 'role:id,name']);
        $existingUser = User::query()->where('email', $invitation->email)->exists();

        return Inertia::render('Auth/AcceptInvitation', [
            'status' => 'pending',
            'token' => $token,
            'invitation' => [
                'organization' => $invitation->organization->name,
                'role' => $invitation->role->name,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
            'userExists' => $existingUser,
            'authenticatedEmail' => Auth::user()?->email,
        ]);
    }

    public function store(Request $request, string $token, InvitationService $invitations): RedirectResponse
    {
        $invitation = $invitations->findByToken($token);

        abort_if(! $invitation, 404);
        abort_if(! $invitation->isPending(), 410, $invitation->isAccepted() ? 'This invitation has already been used.' : 'This invitation has expired.');

        $userExists = User::query()->where('email', $invitation->email)->exists();

        if ($userExists) {
            if (! Auth::check() || Auth::user()->email !== $invitation->email) {
                throw ValidationException::withMessages([
                    'email' => "Log in as {$invitation->email} to accept this invitation.",
                ]);
            }

            $invitations->accept($token);
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);

            $invitations->accept($token, $data);

            Auth::login(User::query()->where('email', $invitation->email)->firstOrFail());
            $request->session()->regenerate();
        }

        return redirect()->route('platform.index')->with('success', 'Invitation acceptée. Bienvenue !');
    }
}
