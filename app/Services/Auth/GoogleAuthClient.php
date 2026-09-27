<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleAuthClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const TOKEN_INFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => config('services.google_auth.scope', 'openid email profile'),
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    public function identityFromCode(string $code): GoogleAuthIdentity
    {
        $token = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $token->successful() || ! is_string($token->json('id_token'))) {
            throw new RuntimeException('Connexion Google impossible.');
        }

        $info = Http::get(self::TOKEN_INFO_URL, ['id_token' => $token->json('id_token')]);
        if (! $info->successful()) {
            throw new RuntimeException('Identité Google invalide.');
        }

        if ((string) $info->json('aud') !== $this->clientId()) {
            throw new RuntimeException('Identité Google invalide.');
        }

        $sub = (string) $info->json('sub');
        $email = Str::lower((string) $info->json('email'));
        $emailVerified = filter_var($info->json('email_verified'), FILTER_VALIDATE_BOOL);

        if ($sub === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Identité Google incomplète.');
        }

        return new GoogleAuthIdentity(
            sub: $sub,
            email: $email,
            emailVerified: $emailVerified,
            name: $this->nullableString($info->json('name')),
            avatarUrl: $this->nullableString($info->json('picture')),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function clientId(): string
    {
        return (string) config('services.google_auth.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.google_auth.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.google_auth.redirect_uri');
    }
}
