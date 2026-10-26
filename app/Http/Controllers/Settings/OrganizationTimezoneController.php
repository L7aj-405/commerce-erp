<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\UpdateOrganizationTimezoneAction;
use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Support\BusinessTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationTimezoneController extends Controller
{
    public function update(Request $request, ActiveTenantContext $context, UpdateOrganizationTimezoneAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);
        $data = $request->validate(['timezone' => BusinessTime::rules()]);

        $action->execute($request->user(), $organization, $data['timezone']);

        return back()->with('success', 'Fuseau horaire de l’organisation enregistré.');
    }
}
