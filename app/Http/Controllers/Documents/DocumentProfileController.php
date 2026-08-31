<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DocumentProfileController extends Controller
{
    public function edit(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        return Inertia::render('Documents/Profile', [
            'organization' => $organization->only(['id', 'name']),
            'profile' => data_get($organization->settings, 'document_profile', []),
        ]);
    }

    public function update(Request $request, ActiveTenantContext $context, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);
        $profile = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'tax_identifier' => ['nullable', 'string', 'max:128'],
            'registration_number' => ['nullable', 'string', 'max:128'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'additional_identifiers' => ['array', 'max:10'],
            'additional_identifiers.*.label' => ['required', 'string', 'max:64'],
            'additional_identifiers.*.value' => ['required', 'string', 'max:128'],
        ]);

        $settings = $organization->settings ?? [];
        $settings['document_profile'] = $profile;
        $organization->settings = $settings;
        $organization->save();

        $audit->record('document_profile.updated', $request->user(), $organization, $context->store(), $organization, newValues: [
            'updated_fields' => array_keys($profile),
        ]);

        return back()->with('success', 'Document profile updated. New drafts will use this profile.');
    }
}
