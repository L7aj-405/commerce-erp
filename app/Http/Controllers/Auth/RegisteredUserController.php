<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ExistingAccountRegistrationAttempted;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 1.1 §5 — public registration must not be usable as a bulk
 * account-discovery oracle. There is deliberately no `unique:users,email`
 * validation rule here: that would return a distinct "this email is already
 * used" error an attacker could script against a wordlist. Instead both
 * branches below (new email vs. already-registered email) produce an
 * IDENTICAL HTTP response — same redirect, same flash message, and crucially
 * neither one authenticates the requester (auto-login on success would
 * itself be the oracle: "am I logged in after submitting?" — and logging the
 * requester into somebody else's existing account is an account-takeover
 * bug, not an option). A genuinely new account still goes through the normal
 * email-verification flow before the owner can sign in.
 */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'name.required' => 'Indiquez votre nom.',
            'email.required' => 'Indiquez votre adresse email.',
            'email.email' => 'Cette adresse email n’est pas valide.',
            'password.required' => 'Choisissez un mot de passe.',
            'password.confirmed' => 'La confirmation ne correspond pas au mot de passe.',
            'password.min' => 'Le mot de passe doit contenir au moins :min caractères.',
        ]);

        $existing = User::query()->where('email', $data['email'])->first();

        if ($existing) {
            // Never disclose that the account exists, never create a
            // duplicate, never authenticate as this other account — point
            // its real owner toward the account-recovery path instead, out
            // of band from this response.
            $existing->notify(new ExistingAccountRegistrationAttempted);

            // Burn roughly the same CPU time as the bcrypt hash the "new
            // account" branch performs below, so response timing does not
            // itself distinguish the two branches.
            Hash::make($data['password']);
        } else {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            event(new Registered($user));
        }

        return redirect()->route('login')->with(
            'status',
            'Vérifiez votre boîte mail pour confirmer votre adresse et vous connecter.',
        );
    }
}
