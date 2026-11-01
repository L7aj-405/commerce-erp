<?php

namespace App\Services\Procurement;

use App\Models\ProductSupplier;
use App\Models\ProductVariant;
use App\Models\Supplier;

/**
 * Supplier picker of the availability-request dialog (Devis line or POS cart
 * line): known suppliers of the article's Product first, then other active
 * suppliers matching the search. Bounded.
 */
final class SupplierOptionsQuery
{
    /** @return list<array{id: int, name: string, phone: ?string, known: bool}> */
    public function forVariant(int $organizationId, ?int $productVariantId, string $search): array
    {
        $productId = $productVariantId
            ? ProductVariant::query()->where('organization_id', $organizationId)->whereKey($productVariantId)->value('product_id')
            : null;
        $knownIds = $productId
            ? ProductSupplier::query()->where('organization_id', $organizationId)->where('product_id', $productId)->limit(50)->pluck('supplier_id')
            : collect();

        $known = Supplier::query()
            ->where('organization_id', $organizationId)->where('active', true)
            ->whereIn('id', $knownIds)->orderBy('name')->get(['id', 'name', 'phone']);

        $others = Supplier::query()
            ->where('organization_id', $organizationId)->where('active', true)
            ->whereNotIn('id', $known->pluck('id'))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone']);

        return [
            ...$known->map(fn (Supplier $s) => [...$s->only(['id', 'name', 'phone']), 'known' => true])->all(),
            ...$others->map(fn (Supplier $s) => [...$s->only(['id', 'name', 'phone']), 'known' => false])->all(),
        ];
    }
}
