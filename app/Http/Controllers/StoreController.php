<?php

namespace App\Http\Controllers;

use App\Enums\WarehouseStatus;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Warehouse;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\StoreCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    /**
     * Paramètres › Magasins: the commercial Stores (points de vente) of the
     * organisation, their default POS inventory location and the locations that
     * belong to them. Members are managed on the existing Utilisateurs & accès
     * screen (linked), never duplicated here.
     */
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Store::class, $organization]);
        $user = $request->user();

        $warehouses = Warehouse::query()->where('organization_id', $organization->getKey())->orderBy('name')
            ->get(['id', 'name', 'code', 'status', 'store_id', 'allow_direct_sales']);
        $memberCounts = StoreMembership::query()->where('organization_id', $organization->getKey())
            ->selectRaw('store_id, COUNT(*) as members')->groupBy('store_id')->pluck('members', 'store_id');

        return Inertia::render('Settings/Stores/Index', [
            'stores' => $organization->stores()->orderBy('name')->get()->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'code' => $store->code,
                'status' => $store->status,
                'default_warehouse_id' => $store->default_warehouse_id,
                'members_count' => (int) ($memberCounts[$store->id] ?? 0),
                'locations' => $warehouses->where('store_id', $store->id)->values()->map(fn (Warehouse $w) => $w->only(['id', 'name', 'code', 'status'])),
            ]),
            'warehouses' => $warehouses->map(fn (Warehouse $w) => [
                ...$w->only(['id', 'name', 'code', 'store_id', 'allow_direct_sales']),
                'status' => $w->status->value,
            ]),
            'usersAccessUrl' => route('users-access.index', $organization),
            'can' => [
                'create' => $user->can('create', [Store::class, $organization]),
                'update' => $user->hasPermission($organization, 'stores.update'),
                'manageAccess' => $user->hasPermission($organization, 'members.view') || $user->hasPermission($organization, 'members.update'),
                'manageWarehouses' => $user->hasPermission($organization, 'warehouses.update'),
            ],
        ]);
    }

    public function show(Store $store): JsonResponse
    {
        $this->authorize('view', $store);

        return response()->json([
            'store' => $store->only(['id', 'organization_id', 'name', 'code', 'status', 'settings']),
        ]);
    }

    public function store(Request $request, ActiveTenantContext $context, StoreCreator $creator): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Store::class, $organization]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', Rule::unique('stores')->where('organization_id', $organization->getKey())],
            'settings' => ['sometimes', 'array'],
        ]);

        $creator->create($request->user(), $organization, $data['name'], $data['code'], $data['settings'] ?? []);

        return back();
    }

    public function update(Request $request, Store $store, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $store);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('stores')->where('organization_id', $store->organization_id)->ignore($store),
            ],
            'settings' => ['sometimes', 'array'],
            'default_tax_rate_id' => [
                'nullable',
                'integer',
                Rule::exists('tax_rates', 'id')->where('organization_id', $store->organization_id),
            ],
            'default_warehouse_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $oldValues = $store->only(['name', 'code', 'settings', 'default_tax_rate_id', 'status', 'default_warehouse_id']);
        $store->update(collect($data)->only(['name', 'code', 'settings', 'default_tax_rate_id'])->all());
        if (array_key_exists('default_warehouse_id', $data)) {
            $store->forceFill(['default_warehouse_id' => $this->defaultLocation($store, $data['default_warehouse_id'])])->save();
        }

        $audit->record(
            'store.updated',
            $request->user(),
            $store->organization,
            $store,
            $store,
            $oldValues,
            $store->only(['name', 'code', 'settings', 'default_tax_rate_id', 'status', 'default_warehouse_id']),
        );

        return back();
    }

    public function destroy(Request $request, Store $store, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('delete', $store);

        $organization = $store->organization;
        $audit->record(
            'store.deleted',
            $request->user(),
            $organization,
            $store,
            $store,
            oldValues: ['name' => $store->name, 'code' => $store->code],
        );
        $store->delete();

        return back();
    }

    /**
     * Activate / deactivate a Store — an explicit act of its own (never a field
     * smuggled into the generic update, see MassAssignmentSecurityTest).
     */
    public function updateStatus(Request $request, Store $store, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('update', $store);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        $old = $store->status;
        $store->forceFill(['status' => $data['status']])->save();
        $audit->record('store.status_changed', $request->user(), $store->organization, $store, $store, ['status' => $old], ['status' => $store->status]);

        return back();
    }

    /**
     * A Store's default POS location: an active location of the organisation
     * that belongs to this Store or is a storage-only depot — never a location
     * of another Store.
     */
    private function defaultLocation(Store $store, mixed $warehouseId): ?int
    {
        if ($warehouseId === null) {
            return null;
        }
        $warehouse = Warehouse::query()->where('organization_id', $store->organization_id)
            ->where('status', WarehouseStatus::Active->value)->whereKey($warehouseId)->first();
        if (! $warehouse || ($warehouse->store_id !== null && (int) $warehouse->store_id !== (int) $store->getKey())) {
            throw ValidationException::withMessages(['default_warehouse_id' => 'Choisissez un emplacement actif de ce magasin ou un dépôt.']);
        }

        return (int) $warehouse->getKey();
    }
}
