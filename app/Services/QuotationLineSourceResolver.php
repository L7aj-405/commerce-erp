<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Models\NonStockItem;
use App\Models\ProductVariant;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the article behind a Devis line (same organisation, active only).
 * Shared by SaveQuotationLineAction and the MCP quotation preview so both apply
 * identical eligibility rules.
 */
final class QuotationLineSourceResolver
{
    public function catalogVariant(int $organizationId, mixed $variantId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->where('organization_id', $organizationId)
            ->whereKey($variantId)
            ->with(['product.defaultUnit', 'taxRate'])
            ->firstOrFail();
        if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Seuls les produits actifs du catalogue peuvent être ajoutés.']);
        }

        return $variant;
    }

    public function nonStockItem(int $organizationId, mixed $itemId): NonStockItem
    {
        $item = NonStockItem::query()
            ->where('organization_id', $organizationId)
            ->whereKey($itemId)
            ->firstOrFail();
        if ($item->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['non_stock_item_id' => 'Cet article hors stock est désactivé.']);
        }

        return $item;
    }
}
