<?php

namespace App\Actions\Catalog;

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;

class UpdateVariantAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, ProductVariant $variant, array $data): ProductVariant
    {
        $fields = ['label', 'sku', 'reference', 'barcode', 'purchase_price', 'default_sale_price', 'tax_rate_id', 'status'];
        $oldValues = $variant->only($fields);
        foreach ($fields as $field) {
            $variant->{$field} = $data[$field] ?? null;
        }
        $variant->save();

        $this->audit->record(
            'product_variant.updated',
            $actor,
            $variant->organization,
            auditable: $variant,
            oldValues: $oldValues,
            newValues: $variant->only($fields),
        );

        return $variant;
    }
}
