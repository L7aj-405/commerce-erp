<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;

class UpdateProductAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Product $product, array $data): Product
    {
        $oldValues = $product->only(['name', 'description', 'image_url', 'brand_id', 'default_category_id', 'default_unit_id', 'status']);
        $product->name = $data['name'];
        $product->description = $data['description'] ?? null;
        $product->image_url = $data['image_url'] ?? null;
        $product->brand_id = $data['brand_id'] ?? null;
        $product->default_category_id = $data['category_id'] ?? null;
        $product->default_unit_id = $data['unit_id'] ?? null;
        $product->status = $data['status'];
        $product->save();

        $this->audit->record(
            'product.updated',
            $actor,
            $product->organization,
            auditable: $product,
            oldValues: $oldValues,
            newValues: $product->only(array_keys($oldValues)),
        );

        return $product;
    }
}
