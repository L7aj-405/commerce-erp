<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseReplenishmentSetting;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Per-warehouse automatic replenishment policy (Stock → Emplacements → …
 * → Réassort automatique). A policy only — it stores no stock.
 */
class WarehouseReplenishmentController extends Controller
{
    public function update(Request $request, ActiveTenantContext $context, Warehouse $warehouse, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        abort_unless(
            $warehouse->organization_id === $organization->getKey()
            && $request->user()->hasPermission($organization, 'inventory.transfer_requests.manage'),
            403,
        );

        $data = $request->validate([
            'auto_replenish' => ['required', 'boolean'],
            'default_minimum_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ]);

        $setting = WarehouseReplenishmentSetting::query()->updateOrCreate(
            ['organization_id' => $organization->getKey(), 'warehouse_id' => $warehouse->getKey()],
            [
                'auto_replenish' => $data['auto_replenish'],
                'default_minimum_quantity' => $data['default_minimum_quantity'],
            ],
        );

        $audit->record('warehouse.replenishment_configured', $request->user(), $organization, auditable: $warehouse, newValues: [
            'warehouse_id' => $warehouse->getKey(),
            'auto_replenish' => $setting->auto_replenish,
            'default_minimum_quantity' => $setting->default_minimum_quantity,
        ]);

        return back()->with('success', 'Réassort automatique mis à jour.');
    }
}
