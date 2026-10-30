<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\UpdateOrganizationFulfillmentModeAction;
use App\Http\Controllers\Controller;
use App\Models\Fulfillment;
use App\Services\ActiveTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationFulfillmentModeController extends Controller
{
    public function update(Request $request, ActiveTenantContext $context, UpdateOrganizationFulfillmentModeAction $action): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);
        $data = $request->validate([
            'fulfillment_mode' => ['required', Rule::in(Fulfillment::MODES)],
            'fulfillment_customer_notifications' => ['sometimes', 'boolean'],
        ]);

        $action->execute($request->user(), $organization, $data['fulfillment_mode'],
            array_key_exists('fulfillment_customer_notifications', $data) ? (bool) $data['fulfillment_customer_notifications'] : null);

        return back()->with('success', 'Mode d’expédition enregistré.');
    }
}
