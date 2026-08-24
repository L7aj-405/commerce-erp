<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission($product->organization_id, 'products.view');
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission($organization, 'products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission($product->organization_id, 'products.update');
    }

    public function archive(User $user, Product $product): bool
    {
        return $user->hasPermission($product->organization_id, 'products.archive');
    }
}
