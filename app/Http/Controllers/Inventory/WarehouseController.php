<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\WarehouseManager;
use App\Support\InventoryQuantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WarehouseController extends Controller
{
    public function index(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Warehouse::class, $organization]);
        $warehouses = $organization->warehouses()->orderBy('name')->get();
        $replenishment = \App\Models\WarehouseReplenishmentSetting::query()
            ->where('organization_id', $organization->getKey())
            ->get()
            ->keyBy('warehouse_id');
        $stats = InventoryBalance::query()
            ->where('organization_id', $organization->getKey())
            ->selectRaw('warehouse_id, COUNT(DISTINCT product_variant_id) as product_count, COALESCE(SUM(on_hand), 0) as unit_count')
            ->groupBy('warehouse_id')
            ->get()
            ->keyBy('warehouse_id');

        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses' => $warehouses->map(function (Warehouse $warehouse) use ($stats, $replenishment) {
                $summary = $stats->get($warehouse->getKey());
                $setting = $replenishment->get($warehouse->getKey());

                return [
                    ...$warehouse->toArray(),
                    'product_count' => (int) ($summary->product_count ?? 0),
                    'unit_count' => InventoryQuantity::normalize($summary->unit_count ?? InventoryQuantity::ZERO),
                    'replenishment' => [
                        'auto_replenish' => (bool) ($setting->auto_replenish ?? false),
                        'default_minimum_quantity' => InventoryQuantity::normalize($setting->default_minimum_quantity ?? InventoryQuantity::ZERO),
                    ],
                ];
            }),
            'can' => [
                'create' => request()->user()?->can('create', [Warehouse::class, $organization]) ?? false,
                'update' => request()->user()?->hasPermission($organization, 'warehouses.update') ?? false,
                'replenishment' => request()->user()?->hasPermission($organization, 'inventory.transfer_requests.manage') ?? false,
            ],
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, WarehouseManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Warehouse::class, $organization]);
        $warehouse = $manager->create($request->user(), $organization, $request->validate($this->rules($organization->getKey())));

        return redirect()
            ->route('inventory.warehouses.index')
            ->with('success', 'Emplacement cree avec succes.')
            ->with('warehouse_created_id', $warehouse->getKey());
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseManager $manager): RedirectResponse
    {
        $this->authorize('update', $warehouse);
        $manager->update($request->user(), $warehouse, $request->validate($this->rules($warehouse->organization_id, $warehouse)));

        return back()->with('success', 'Emplacement mis a jour.');
    }

    /** @return array<string, mixed> */
    private function rules(int $organizationId, ?Warehouse $warehouse = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', Rule::unique('warehouses', 'code')->where('organization_id', $organizationId)->ignore($warehouse)],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::enum(WarehouseStatus::class)],
        ];
    }
}
