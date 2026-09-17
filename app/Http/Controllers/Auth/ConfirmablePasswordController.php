<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Confirm your password" step (§E8) required before a sensitive account
 * action — disabling 2FA, regenerating recovery codes, and any future
 * change-password/change-email page. Pairs with Laravel's built-in
 * `password.confirm` middleware (Illuminate\Auth\Middleware\RequirePassword),
 * which redirects here when confirmation is missing or has expired
 * (config('auth.password_timeout'), default 3 hours) and otherwise lets the
 * request straight through.
 */
class ConfirmablePasswordController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/ConfirmPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->input('password'),
        ])) {
            throw ValidationException::withMessages(['password' => 'Mot de passe incorrect.']);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended();
    }
}
