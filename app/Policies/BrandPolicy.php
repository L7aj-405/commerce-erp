<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;

class BrandPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'brands.view');
    }

    public function view(User $user, Brand $brand): bool
    {
        return $user->hasPermission($brand->organization_id, 'brands.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'brands.manage');
    }

    public function update(User $user, Brand $brand): bool
    {
        return $user->hasPermission($brand->organization_id, 'brands.manage');
    }
}
