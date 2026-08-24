<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\UnitOfMeasure;
use App\Models\User;

class UnitOfMeasurePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'units.view');
    }

    public function view(User $user, UnitOfMeasure $unit): bool
    {
        return $user->hasPermission($unit->organization_id, 'units.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'units.manage');
    }

    public function update(User $user, UnitOfMeasure $unit): bool
    {
        return $user->hasPermission($unit->organization_id, 'units.manage');
    }
}
