<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Enums\InventoryMovementType;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Support\InventoryQuantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryStockController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [InventoryBalance::class, $organization]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'warehouse' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organization->getKey())],
            'brand' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('organization_id', $organization->getKey())],
            'category' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('organization_id', $organization->getKey())],
            'availability' => ['nullable', Rule::in(['in_stock', 'out_of_stock', 'low_stock'])],
        ]);

        $warehouses = $organization->warehouses()
            ->where('status', WarehouseStatus::Active->value)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $selectedWarehouseId = $filters['warehouse'] ?? null;
        $variants = ProductVariant::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->with([
                'product:id,name,brand_id,default_category_id,image_url',
                'product.brand:id,name',
                'product.defaultCategory:id,name',
            ])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('sku', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($product) => $product
                            ->where('name', 'like', "%{$search}%")
                            ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', "%{$search}%")));
                });
            })
            ->when($filters['brand'] ?? null, fn ($query, int $brand) => $query->whereHas('product', fn ($product) => $product->where('brand_id', $brand)))
            ->when($filters['category'] ?? null, fn ($query, int $category) => $query->whereHas('product', fn ($product) => $product->where('default_category_id', $category)))
            ->when($selectedWarehouseId, fn ($query, int $warehouseId) => $query->whereHas('inventoryBalances', fn ($balance) => $balance
                ->where('organization_id', $organization->getKey())
                ->where('warehouse_id', $warehouseId)))
            ->when($filters['availability'] ?? null, function ($query, string $availability) use ($organization, $selectedWarehouseId) {
                $query->whereHas('inventoryBalances', function ($balance) use ($organization, $selectedWarehouseId, $availability) {
                    $balance->where('organization_id', $organization->getKey());
                    if ($selectedWarehouseId) {
                        $balance->where('warehouse_id', $selectedWarehouseId);
                    }
                    match ($availability) {
                        'in_stock' => $balance->whereRaw('(on_hand - reserved) > 0'),
                        'out_of_stock' => $balance->whereRaw('(on_hand - reserved) <= 0'),
                        'low_stock' => $balance->whereRaw('(on_hand - reserved) > 0')->whereRaw('(on_hand - reserved) <= 5'),
                    };
                });
            })
            ->orderBy('sku')
            ->paginate(20)
            ->withQueryString();

        $stockByVariant = $this->stockByVariant(
            $organization->getKey(),
            $variants,
            $selectedWarehouseId ? [$selectedWarehouseId] : $warehouses->pluck('id')->all(),
        );

        $balances = $variants->through(function (ProductVariant $variant) use ($stockByVariant, $selectedWarehouseId) {
            $warehouseStock = collect($stockByVariant[$variant->getKey()] ?? []);
            $summary = $warehouseStock->reduce(function (array $carry, array $warehouse) {
                $carry['on_hand'] = InventoryQuantity::add($carry['on_hand'], $warehouse['on_hand']);
                $carry['reserved'] = InventoryQuantity::add($carry['reserved'], $warehouse['reserved']);
                $carry['available'] = InventoryQuantity::add($carry['available'], $warehouse['available']);

                return $carry;
            }, [
                'on_hand' => InventoryQuantity::ZERO,
                'reserved' => InventoryQuantity::ZERO,
                'available' => InventoryQuantity::ZERO,
            ]);

            return [
                'id' => $variant->getKey(),
                'label' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'barcode' => $variant->barcode,
                'image_url' => $variant->image_url ?? $variant->product->image_url,
                'product' => [
                    'id' => $variant->product->getKey(),
                    'name' => $variant->product->name,
                    'image_url' => $variant->product->image_url,
                    'brand' => $variant->product->brand?->only(['id', 'name']),
                    'category' => $variant->product->defaultCategory?->only(['id', 'name']),
                ],
                'warehouses' => $warehouseStock->values()->all(),
                'summary' => $selectedWarehouseId && $warehouseStock->first()
                    ? $warehouseStock->first()
                    : $summary,
            ];
        });

        return Inertia::render('Inventory/Stock/Index', [
            'balances' => $balances,
            'filters' => $filters,
            'warehouses' => $warehouses,
            'brands' => $organization->brands()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'categories' => $organization->categories()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'variants' => ProductVariant::query()->where('organization_id', $organization->getKey())->where('status', 'active')
                ->with('product:id,name')->orderBy('sku')->limit(500)->get(['id', 'product_id', 'label', 'sku']),
            'can' => [
                'opening' => $request->user()->can('opening', [InventoryBalance::class, $organization]),
                'adjust' => $request->user()->can('adjust', [InventoryBalance::class, $organization]),
                'transfer' => $request->user()->hasPermission($organization, 'inventory.transfer'),
            ],
        ]);
    }

    public function opening(Request $request, ActiveTenantContext $context, OpeningStockAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('opening', [InventoryBalance::class, $organization]);
        $data = $request->validate($this->operationRules($organization->getKey(), false));
        $action->execute($request->user(), $organization, $this->warehouse($organization->getKey(), $data['warehouse_id']), $this->variant($organization->getKey(), $data['product_variant_id']), $data['quantity'], $data['reason'] ?? null, $data['reference'] ?? null);

        return back();
    }

    public function adjustment(Request $request, ActiveTenantContext $context, AdjustInventoryAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('adjust', [InventoryBalance::class, $organization]);
        $data = $request->validate($this->operationRules($organization->getKey(), true));
        $action->execute($request->user(), $organization, $this->warehouse($organization->getKey(), $data['warehouse_id']), $this->variant($organization->getKey(), $data['product_variant_id']), InventoryMovementType::from($data['type']), $data['quantity'], $data['reason'], $data['reference'] ?? null);

        return back();
    }

    /** @return array<string, mixed> */
    private function operationRules(int $organizationId, bool $adjustment): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active'))],
            'type' => $adjustment ? ['required', Rule::in([InventoryMovementType::AdjustmentIn->value, InventoryMovementType::AdjustmentOut->value])] : ['prohibited'],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'reason' => $adjustment ? ['required', 'string', 'max:2000'] : ['nullable', 'string', 'max:2000'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function warehouse(int $organizationId, int $id): Warehouse
    {
        return Warehouse::query()->where('organization_id', $organizationId)->whereKey($id)->firstOrFail();
    }

    private function variant(int $organizationId, int $id): ProductVariant
    {
        return ProductVariant::query()->where('organization_id', $organizationId)->whereKey($id)->firstOrFail();
    }

    /**
     * @param  LengthAwarePaginator<int, ProductVariant>  $variants
     * @param  list<int>  $warehouseIds
     * @return array<int, array<int, array{name: string, code: string, on_hand: string, reserved: string, available: string}>>
     */
    private function stockByVariant(
    int $organizationId,
    LengthAwarePaginator $variants,
    array $warehouseIds
): array {
    $variantIds = collect($variants->items())
        ->pluck('id')
        ->values()
        ->all();

    if ($variantIds === [] || $warehouseIds === []) {
        return [];
    }

    $rows = InventoryBalance::query()
        ->join('warehouses', function ($join) {
            $join->on(
                'warehouses.id',
                '=',
                'inventory_balances.warehouse_id'
            )->on(
                'warehouses.organization_id',
                '=',
                'inventory_balances.organization_id'
            );
        })
        ->where(
            'inventory_balances.organization_id',
            $organizationId
        )
        ->whereIn(
            'inventory_balances.product_variant_id',
            $variantIds
        )
        ->whereIn(
            'inventory_balances.warehouse_id',
            $warehouseIds
        )
        ->orderBy('warehouses.name')
        ->get([
            'inventory_balances.product_variant_id',
            'inventory_balances.on_hand',
            'inventory_balances.reserved',
            'warehouses.id as warehouse_id',
            'warehouses.name',
            'warehouses.code',
        ]);

    $stock = [];

    foreach ($rows as $row) {
        $available = InventoryQuantity::subtract(
            $row->on_hand,
            $row->reserved
        );

        $stock[$row->product_variant_id][$row->warehouse_id] = [
            'id' => $row->warehouse_id,
            'name' => $row->name,
            'code' => $row->code,
            'on_hand' => $row->on_hand,
            'reserved' => $row->reserved,
            'available' => $available,
        ];
    }

    return $stock;
}
}
