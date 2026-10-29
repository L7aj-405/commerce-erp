<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\ProductVariantSupplier;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Canonical read model of the supplier catalog (Phase B1), shared by the web
 * pages and MCP: suppliers of variants, variants of a supplier, the preferred
 * supplier, known terms and purchase-history signals.
 *
 * Purchase history is derived from purchase orders, never stored on the
 * relation: per (supplier, variant) the latest non-draft, non-cancelled PO
 * line, the total ordered quantity and the number of orders — two grouped
 * queries for a whole page, never one per relation.
 *
 * No ranking or recommendation here (B2 consumes this model).
 */
final class SupplierCatalogQuery
{
    private const PURCHASED_STATUSES = [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Received];

    /** Supplier prices are purchase-cost data: visible with the cost or the procurement view permission (PO pages show unit prices). */
    public static function canViewPrices(User $user, int $organizationId): bool
    {
        return $user->hasPermission($organizationId, 'product_cost.view') || $user->hasPermission($organizationId, 'procurement.view');
    }

    /**
     * Relations of the given variants, keyed by variant id: preferred first,
     * then active, then by priority and supplier name.
     *
     * @param list<int> $variantIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forVariants(int $organizationId, array $variantIds, bool $withPrices, bool $includeInactive = true): array
    {
        if ($variantIds === []) return [];
        $relations = ProductVariantSupplier::query()->where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->when(! $includeInactive, fn ($query) => $query->where('status', ProductVariantSupplier::STATUS_ACTIVE))
            ->with(['supplier:id,name,active', 'variant:id,product_id,sku,label,reference,status', 'variant.product:id,name'])
            ->get();
        $history = $this->lastPurchases($organizationId, $relations->pluck('product_variant_id')->unique()->values()->all(), null);

        return $relations
            ->sortBy(fn (ProductVariantSupplier $relation) => [
                $relation->is_preferred ? 0 : 1,
                $relation->status === ProductVariantSupplier::STATUS_ACTIVE ? 0 : 1,
                $relation->priority ?? 1000,
                mb_strtolower((string) $relation->supplier?->name),
            ])
            ->groupBy('product_variant_id')
            ->map(fn ($group) => $group->map(fn ($relation) => $this->present($relation, $history, $withPrices))->values()->all())
            ->all();
    }

    /**
     * Variants supplied by one supplier, paginated, ordered by product name and SKU.
     *
     * @param array{status?: string|null, search?: string|null} $filters
     */
    public function forSupplier(int $organizationId, int $supplierId, array $filters, bool $withPrices, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $paginator = ProductVariantSupplier::query()->from('product_variant_suppliers as pvs')
            ->join('product_variants as pv', fn ($join) => $join->on('pv.id', '=', 'pvs.product_variant_id')->on('pv.organization_id', '=', 'pvs.organization_id'))
            ->join('products as p', fn ($join) => $join->on('p.id', '=', 'pv.product_id')->on('p.organization_id', '=', 'pv.organization_id'))
            ->where('pvs.organization_id', $organizationId)->where('pvs.supplier_id', $supplierId)
            ->when(in_array($filters['status'] ?? null, [ProductVariantSupplier::STATUS_ACTIVE, ProductVariantSupplier::STATUS_INACTIVE], true), fn ($query) => $query->where('pvs.status', $filters['status']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('p.name', 'like', "%{$search}%")->orWhere('pv.sku', 'like', "%{$search}%")
                ->orWhere('pv.reference', 'like', "%{$search}%")->orWhere('pvs.supplier_sku', 'like', "%{$search}%")
                ->orWhere('pvs.supplier_reference', 'like', "%{$search}%")))
            ->orderBy('p.name')->orderBy('pv.sku')->orderBy('pvs.id')
            ->select('pvs.*')
            ->with(['supplier:id,name,active', 'variant:id,product_id,sku,label,reference,status', 'variant.product:id,name'])
            ->paginate(max(1, min($perPage, 100)), ['*'], 'page', max(1, $page));
        $variantIds = collect($paginator->items())->pluck('product_variant_id')->unique()->values()->all();
        $history = $this->lastPurchases($organizationId, $variantIds, $supplierId);
        $preferred = $this->preferredByVariant($organizationId, $variantIds);

        return $paginator->through(fn (ProductVariantSupplier $relation) => [
            ...$this->present($relation, $history, $withPrices),
            // The variant's current preferred supplier (possibly another one): lets the form warn before a replacement.
            'variant_preferred' => $preferred[$relation->product_variant_id] ?? null,
        ]);
    }

    /**
     * Current preferred supplier per variant, one query.
     *
     * @param list<int> $variantIds
     * @return array<int, array{relation_id: int, supplier_id: int, supplier_name: string|null}>
     */
    public function preferredByVariant(int $organizationId, array $variantIds): array
    {
        if ($variantIds === []) return [];

        return ProductVariantSupplier::query()->where('organization_id', $organizationId)->whereIn('product_variant_id', $variantIds)
            ->where('is_preferred', true)->with('supplier:id,name')->get(['id', 'supplier_id', 'product_variant_id'])
            ->mapWithKeys(fn (ProductVariantSupplier $relation) => [(int) $relation->product_variant_id => [
                'relation_id' => (int) $relation->getKey(), 'supplier_id' => (int) $relation->supplier_id, 'supplier_name' => $relation->supplier?->name,
            ]])->all();
    }

    public function find(int $organizationId, int $relationId): ProductVariantSupplier
    {
        return ProductVariantSupplier::query()->where('organization_id', $organizationId)->whereKey($relationId)
            ->with(['supplier:id,name,active', 'variant:id,product_id,sku,label,reference,status', 'variant.product:id,name'])->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function one(ProductVariantSupplier $relation, bool $withPrices): array
    {
        $relation->loadMissing(['supplier:id,name,active', 'variant:id,product_id,sku,label,reference,status', 'variant.product:id,name']);

        return $this->present($relation, $this->lastPurchases((int) $relation->organization_id, [(int) $relation->product_variant_id], (int) $relation->supplier_id), $withPrices);
    }

    /**
     * Latest purchase signals per "supplier:variant".
     *
     * @param list<int> $variantIds
     * @return array<string, array<string, mixed>>
     */
    public function lastPurchases(int $organizationId, array $variantIds, ?int $supplierId): array
    {
        if ($variantIds === []) return [];
        $statuses = array_map(fn (PurchaseOrderStatus $status) => $status->value, self::PURCHASED_STATUSES);
        $base = fn () => DB::table('purchase_order_lines as pol')
            ->join('purchase_orders as po', fn ($join) => $join->on('po.id', '=', 'pol.purchase_order_id')->on('po.organization_id', '=', 'pol.organization_id'))
            ->where('pol.organization_id', $organizationId)
            ->whereIn('po.status', $statuses)
            ->whereIn('pol.product_variant_id', $variantIds)
            ->when($supplierId !== null, fn ($query) => $query->where('po.supplier_id', $supplierId));

        $groups = $base()->groupBy('po.supplier_id', 'pol.product_variant_id')
            ->selectRaw('po.supplier_id, pol.product_variant_id, max(pol.id) as last_line_id, sum(pol.quantity) as total_quantity, count(distinct po.id) as order_count')
            ->get();
        if ($groups->isEmpty()) return [];
        $lines = $base()->whereIn('pol.id', $groups->pluck('last_line_id')->all())
            ->get(['pol.id', 'pol.unit_price', 'pol.quantity', 'po.id as purchase_order_id', 'po.purchase_order_number', 'po.order_date', 'po.currency_code', 'po.status'])
            ->keyBy('id');

        $history = [];
        foreach ($groups as $group) {
            $line = $lines[$group->last_line_id] ?? null;
            if ($line === null) continue;
            $history[$group->supplier_id.':'.$group->product_variant_id] = [
                'purchase_order_id' => (int) $line->purchase_order_id,
                'purchase_order_number' => $line->purchase_order_number,
                'order_date' => substr((string) $line->order_date, 0, 10),
                'status' => $line->status,
                'unit_price' => $line->unit_price === null ? null : Decimal::normalize((string) $line->unit_price),
                'quantity' => Decimal::normalize((string) $line->quantity),
                'currency_code' => $line->currency_code,
                'total_quantity' => Decimal::normalize((string) $group->total_quantity),
                'order_count' => (int) $group->order_count,
            ];
        }

        return $history;
    }

    /** @param array<string, array<string, mixed>> $history @return array<string, mixed> */
    private function present(ProductVariantSupplier $relation, array $history, bool $withPrices): array
    {
        $last = $history[$relation->supplier_id.':'.$relation->product_variant_id] ?? null;
        if ($last !== null && ! $withPrices) $last['unit_price'] = null;
        $variant = $relation->variant;

        return [
            'id' => (int) $relation->getKey(),
            'supplier' => ['id' => (int) $relation->supplier_id, 'name' => $relation->supplier?->name, 'active' => (bool) $relation->supplier?->active],
            'variant' => [
                'id' => (int) $relation->product_variant_id, 'sku' => $variant?->sku, 'label' => $variant?->label, 'reference' => $variant?->reference,
                'status' => $variant?->status?->value, 'product' => ['id' => (int) $variant?->product_id, 'name' => $variant?->product?->name],
            ],
            'supplier_sku' => $relation->supplier_sku,
            'supplier_reference' => $relation->supplier_reference,
            'supplier_product_name' => $relation->supplier_product_name,
            'purchase_price' => $withPrices ? $relation->purchase_price : null,
            'prices_visible' => $withPrices,
            'currency_code' => $relation->currency_code,
            'minimum_order_quantity' => $relation->minimum_order_quantity,
            'order_multiple' => $relation->order_multiple,
            'lead_time_days' => $relation->lead_time_days,
            'is_preferred' => (bool) $relation->is_preferred,
            'priority' => $relation->priority,
            'status' => $relation->status,
            'quality_rating' => $relation->quality_rating,
            'reliability_rating' => $relation->reliability_rating,
            'notes' => $relation->notes,
            'updated_at' => $relation->updated_at?->toIso8601String(),
            'last_purchase' => $last,
        ];
    }
}
