<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'name.required' => 'Indiquez votre nom.',
            'email.required' => 'Indiquez votre adresse email.',
            'email.email' => 'Cette adresse email n’est pas valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'password.required' => 'Choisissez un mot de passe.',
            'password.confirmed' => 'La confirmation ne correspond pas au mot de passe.',
            'password.min' => 'Le mot de passe doit contenir au moins :min caractères.',
        ]);

        // The User model casts `password` as `hashed`, so store the plain value here.
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('platform.index');
    }
}
