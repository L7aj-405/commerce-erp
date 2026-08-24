<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ConsumeReservationAction;
use App\Actions\Inventory\CreateReservationAction;
use App\Actions\Inventory\ReleaseReservationAction;
use App\Http\Controllers\Controller;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryReservationController extends Controller
{
    public function store(Request $request, ActiveTenantContext $context, CreateReservationAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [InventoryReservation::class, $organization]);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->where(fn ($query) => $query->where('organization_id', $organization->getKey())->where('status', 'active'))],
            'quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'reference_type' => ['nullable', 'string', 'max:255', 'required_with:reference_id'],
            'reference_id' => ['nullable', 'integer', 'min:1', 'required_with:reference_type'],
            'reference' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $warehouse = Warehouse::query()->where('organization_id', $organization->getKey())->whereKey($data['warehouse_id'])->firstOrFail();
        $variant = ProductVariant::query()->where('organization_id', $organization->getKey())->whereKey($data['product_variant_id'])->firstOrFail();
        $action->execute($request->user(), $organization, $warehouse, $variant, $data['quantity'], $data['reference_type'] ?? null, $data['reference_id'] ?? null, $data['reference'] ?? null, $data['expires_at'] ?? null);

        return back();
    }

    public function release(Request $request, InventoryReservation $reservation, ReleaseReservationAction $action): RedirectResponse
    {
        $this->authorize('release', $reservation);
        $action->execute($request->user(), $reservation->organization, $reservation);

        return back();
    }

    public function consume(Request $request, InventoryReservation $reservation, ConsumeReservationAction $action): RedirectResponse
    {
        $this->authorize('consume', $reservation);
        $action->execute($request->user(), $reservation->organization, $reservation);

        return back();
    }
}
