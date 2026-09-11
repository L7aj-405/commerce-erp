<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\StoreCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
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
        ]);

        $oldValues = $store->only(['name', 'code', 'settings', 'default_tax_rate_id']);
        $store->update($data);

        $audit->record(
            'store.updated',
            $request->user(),
            $store->organization,
            $store,
            $store,
            $oldValues,
            $store->only(['name', 'code', 'settings', 'default_tax_rate_id']),
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
}
