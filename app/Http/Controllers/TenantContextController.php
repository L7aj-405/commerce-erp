<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Store;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantContextController extends Controller
{
    public function organization(Request $request, int $organizationId, ActiveTenantContext $context): RedirectResponse
    {
        $organization = Organization::query()->findOrFail($organizationId);
        $context->activateOrganization($request->user(), $organization);

        return back();
    }

    public function store(Request $request, int $storeId, ActiveTenantContext $context): RedirectResponse
    {
        $store = Store::query()->findOrFail($storeId);
        $context->activateStore($request->user(), $store);

        return back();
    }
}
