<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\ReturnPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReturnPolicyController extends Controller
{
    public function edit(Request $request, ActiveTenantContext $context, ReturnPolicyService $policies): Response
    {
        $organization = $context->organizationOrFail(); $store = $context->store(); $this->authorize('viewSettings', $organization);
        return Inertia::render('Settings/ReturnPolicy', ['organization' => $organization->only(['id', 'name']), 'store' => $store?->only(['id', 'name']), 'organizationPolicy' => data_get($organization->settings, 'return_policy', []), 'storePolicy' => $store ? data_get($store->settings, 'return_policy') : null, 'resolved' => $store ? $policies->resolve($organization, $store) : null, 'canUpdate' => $request->user()->hasPermission($organization, 'settings.update')]);
    }

    public function update(Request $request, ActiveTenantContext $context, ReturnPolicyService $policies, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail(); $this->authorize('updateSettings', $organization);
        $data = $request->validate(['scope' => ['required', 'in:organization,store'], 'inherit' => ['sometimes', 'boolean'], 'enabled' => ['required_unless:inherit,true', 'boolean'], 'window_value' => ['required_unless:inherit,true', 'integer', 'min:0', 'max:365'], 'window_unit' => ['required_unless:inherit,true', 'in:hours,days'], 'allow_partial' => ['required_unless:inherit,true', 'boolean'], 'allow_full' => ['required_unless:inherit,true', 'boolean'], 'manager_override_allowed' => ['required_unless:inherit,true', 'boolean'], 'require_reason' => ['required_unless:inherit,true', 'boolean'], 'default_disposition' => ['required_unless:inherit,true', 'in:restock,damaged']]);
        $target = $data['scope'] === 'store' ? $context->storeOrFail() : $organization;
        $settings = $target->settings ?? [];
        if ($data['scope'] === 'store' && ($data['inherit'] ?? false)) $settings['return_policy'] = ['inherit' => true];
        else $settings['return_policy'] = ['enabled' => (bool) $data['enabled'], 'window_value' => (int) $data['window_value'], 'window_unit' => $data['window_unit'], 'window_minutes' => $policies->normalizeWindow((int) $data['window_value'], $data['window_unit']), 'allow_partial' => (bool) $data['allow_partial'], 'allow_full' => (bool) $data['allow_full'], 'manager_override_allowed' => (bool) $data['manager_override_allowed'], 'require_reason' => (bool) $data['require_reason'], 'default_disposition' => $data['default_disposition']];
        $target->settings = $settings; $target->save();
        $audit->record('return_policy.updated', $request->user(), $organization, $context->store(), $target, newValues: ['scope' => $data['scope'], 'policy' => $settings['return_policy']]);
        return back()->with('success', 'Politique de retour enregistrée.');
    }
}
