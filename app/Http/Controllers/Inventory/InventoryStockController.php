<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Actions\Inventory\OpeningStockAction;
use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
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
        ]);

        $balances = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->with(['warehouse:id,name,code', 'productVariant:id,product_id,label,sku', 'productVariant.product:id,name'])
            ->when($filters['warehouse'] ?? null, fn ($query, int $warehouse) => $query->where('warehouse_id', $warehouse))
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->whereHas('productVariant', fn ($query) => $query
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('label', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->orderBy('warehouse_id')->orderBy('product_variant_id')
            ->paginate(20)->withQueryString();

        return Inertia::render('Inventory/Stock/Index', [
            'balances' => $balances,
            'filters' => $filters,
            'warehouses' => $organization->warehouses()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'variants' => ProductVariant::query()->where('organization_id', $organization->getKey())->where('status', 'active')
                ->with('product:id,name')->orderBy('sku')->limit(500)->get(['id', 'product_id', 'label', 'sku']),
            'can' => [
                'opening' => $request->user()->can('opening', [InventoryBalance::class, $organization]),
                'adjust' => $request->user()->can('adjust', [InventoryBalance::class, $organization]),
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
}
