<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetInitialUserPasswordAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->password !== null) {
                throw ValidationException::withMessages([
                    'password' => 'Un mot de passe est déjà configuré pour ce compte.',
                ]);
            }

            $locked->password = $password;
            $locked->save();

            $this->audit->record('auth.initial_password_set', $locked, $locked->activeOrganization, auditable: $locked);
        });
    }
}
