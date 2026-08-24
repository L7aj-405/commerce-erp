<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InventoryMovementController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [InventoryMovement::class, $organization]);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'warehouse' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $organization->getKey())],
        ]);

        $movements = InventoryMovement::query()
            ->where('organization_id', $organization->getKey())
            ->with([
                'warehouse:id,name,code',
                'productVariant:id,product_id,label,sku',
                'productVariant.product:id,name',
                'performedBy:id,name',
            ])
            ->when($filters['warehouse'] ?? null, fn ($query, int $warehouse) => $query->where('warehouse_id', $warehouse))
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->whereHas('productVariant', fn ($query) => $query
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($query) => $query->where('name', 'like', "%{$search}%")));
            })
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Inventory/Movements/Index', [
            'movements' => $movements,
            'filters' => $filters,
            'warehouses' => Warehouse::query()->where('organization_id', $organization->getKey())->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }
}
