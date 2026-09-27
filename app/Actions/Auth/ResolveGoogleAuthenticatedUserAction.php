<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\UserSocialIdentity;
use App\Services\Auth\GoogleAuthIdentity;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResolveGoogleAuthenticatedUserAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(GoogleAuthIdentity $identity): User
    {
        if (! $identity->emailVerified) {
            throw new RuntimeException('Votre adresse e-mail Google doit être vérifiée pour continuer.');
        }

        return DB::transaction(function () use ($identity): User {
            $social = UserSocialIdentity::query()
                ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
                ->where('provider_user_id', $identity->sub)
                ->lockForUpdate()
                ->first();

            if ($social) {
                $this->updateIdentityMetadata($social, $identity);
                $this->audit->record('auth.google_login', $social->user, $social->user->activeOrganization, auditable: $social->user);

                return $social->user()->firstOrFail();
            }

            $user = User::query()
                ->where('email', $identity->email)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                $user = new User;
                $user->name = $identity->name ?: $identity->email;
                $user->email = $identity->email;
                $user->password = null;
                $user->email_verified_at = now();
                try {
                    $user->save();
                } catch (QueryException) {
                    $user = User::query()->where('email', $identity->email)->lockForUpdate()->firstOrFail();
                }
            } elseif (! $user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
            }

            try {
                $social = new UserSocialIdentity;
                $social->user_id = $user->getKey();
                $social->provider = UserSocialIdentity::PROVIDER_GOOGLE;
                $social->provider_user_id = $identity->sub;
                $this->updateIdentityMetadata($social, $identity, save: false);
                $social->save();
            } catch (QueryException) {
                $social = UserSocialIdentity::query()
                    ->where('provider', UserSocialIdentity::PROVIDER_GOOGLE)
                    ->where('provider_user_id', $identity->sub)
                    ->firstOrFail();
                $user = $social->user()->firstOrFail();
            }

            $this->audit->record('auth.google_identity_linked', $user, $user->activeOrganization, auditable: $user, newValues: [
                'provider' => UserSocialIdentity::PROVIDER_GOOGLE,
                'provider_email' => $identity->email,
            ]);
            $this->audit->record('auth.google_login', $user, $user->activeOrganization, auditable: $user);

            return $user;
        });
    }

    private function updateIdentityMetadata(UserSocialIdentity $social, GoogleAuthIdentity $identity, bool $save = true): void
    {
        $social->provider_email = $identity->email;
        $social->provider_name = $identity->name;
        $social->provider_avatar_url = $identity->avatarUrl;

        if ($save && $social->isDirty()) {
            $social->save();
        }
    }
}
