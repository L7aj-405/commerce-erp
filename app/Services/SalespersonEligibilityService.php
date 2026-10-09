<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class SalespersonEligibilityService
{
    /** @return Builder<User> */
    public function query(Organization|int $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        return User::query()
            ->whereHas('organizationMemberships', fn ($memberships) => $memberships
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereHas('role.permissions', fn ($permissions) => $permissions
                    ->whereIn('key', ['sales_orders.create', 'pos.access'])));
    }

    /** @return Collection<int, User> */
    public function choices(Organization|int $organization): Collection
    {
        return $this->query($organization)->orderBy('name')->get(['users.id', 'users.name']);
    }

    public function eligible(Organization|int $organization, User|int $user): bool
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $this->query($organization)->whereKey($userId)->exists();
    }

    public function resolve(Organization|int $organization, mixed $userId): ?User
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $user = $this->query($organization)->whereKey($userId)->first();
        if (! $user) {
            throw ValidationException::withMessages([
                'salesperson_id' => 'Le commercial sélectionné n’est pas un membre actif autorisé de cette organisation.',
            ]);
        }

        return $user;
    }
}
