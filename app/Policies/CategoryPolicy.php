<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Organization;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'categories.view');
    }

    public function view(User $user, Category $category): bool
    {
        return $user->hasPermission($category->organization_id, 'categories.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'categories.manage');
    }

    public function update(User $user, Category $category): bool
    {
        return $user->hasPermission($category->organization_id, 'categories.manage');
    }
}
