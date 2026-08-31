<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\ActiveTenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var ActiveTenantContext $context */
        $context = app(ActiveTenantContext::class);
        $organization = $context->organization();
        $store = $context->store();
        $user = $request->user();
        $organizations = $user ? Organization::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey())->where('status', 'active'))
            ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'status']) : collect();
        $stores = $user && $organization ? $organization->stores()->where('status', 'active')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))
            ->orderBy('name')->get(['id', 'organization_id', 'name', 'code', 'status']) : collect();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email']),
            ],
            'tenant' => [
                'organization' => $organization?->only(['id', 'name', 'status']),
                'store' => $store?->only(['id', 'organization_id', 'name', 'code', 'status']),
                'organizations' => $organizations,
                'stores' => $stores,
                'permissions' => $request->user() && $organization
                    ? $request->user()->permissionKeysFor($organization)
                    : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warehouseCreatedId' => fn () => $request->session()->get('warehouse_created_id'),
            ],
        ];
    }
}
