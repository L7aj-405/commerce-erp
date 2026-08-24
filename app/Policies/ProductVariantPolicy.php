<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy
{
    public function create(User $user, Product $product): bool
    {
        return $user->hasPermission($product->organization_id, 'products.create');
    }

    public function update(User $user, ProductVariant $variant): bool
    {
        return $user->hasPermission($variant->organization_id, 'products.update');
    }

    public function archive(User $user, ProductVariant $variant): bool
    {
        return $user->hasPermission($variant->organization_id, 'products.archive');
    }
}
