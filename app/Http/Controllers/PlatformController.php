<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\ActiveTenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformController extends Controller
{
    public function __invoke(Request $request, ActiveTenantContext $context): Response
    {
        $user = $request->user();
        $organization = $context->organization();

        $organizations = Organization::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('status', 'active'))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'status']);

        $stores = $organization
            ? $organization->stores()
                ->where('status', 'active')
                ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))
                ->orderBy('name')
                ->get(['id', 'organization_id', 'name', 'code', 'status'])
            : collect();

        return Inertia::render('Platform/Index', [
            'organizations' => $organizations,
            'stores' => $stores,
        ]);
    }
}
