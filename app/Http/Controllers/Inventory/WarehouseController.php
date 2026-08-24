<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\WarehouseStatus;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\WarehouseManager;
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

        return Inertia::render('Inventory/Warehouses/Index', [
            'warehouses' => $organization->warehouses()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, WarehouseManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Warehouse::class, $organization]);
        $manager->create($request->user(), $organization, $request->validate($this->rules($organization->getKey())));

        return back();
    }

    public function update(Request $request, Warehouse $warehouse, WarehouseManager $manager): RedirectResponse
    {
        $this->authorize('update', $warehouse);
        $manager->update($request->user(), $warehouse, $request->validate($this->rules($warehouse->organization_id, $warehouse)));

        return back();
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
