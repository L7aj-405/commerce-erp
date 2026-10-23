<?php

namespace App\Actions\Catalog;

use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Decimal;

final class UpdateProductPurchasePriceAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, ProductVariant $variant, string $purchasePrice): ProductVariant
    {
        $normalized = Decimal::nonNegative($purchasePrice, 'purchase_price');
        $old = $variant->purchase_price;
        $variant->purchase_price = $normalized;
        $variant->save();
        $this->audit->record(
            'product_cost.updated',
            $actor,
            $variant->organization,
            auditable: $variant,
            oldValues: ['purchase_price' => $old],
            newValues: ['purchase_price' => $variant->purchase_price],
        );

        return $variant;
    }
}
