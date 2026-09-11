<?php

namespace App\Http\Controllers\Quotations;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\QuotationDocumentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres → Documents → Devis.
 *
 * Stable Devis-only options stored on organization.settings.quotation_profile.
 * The company identity (logo, legal name, ICE, RC, IF, TP, bank, RIB, …) is NOT
 * duplicated here — it comes from the shared Document/Company Profile.
 *
 * Changing these settings NEVER mutates a Devis that already froze its own
 * seller snapshot.
 */
class QuotationSettingsController extends Controller
{
    public function edit(ActiveTenantContext $context, QuotationDocumentSettings $settings): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        return Inertia::render('Settings/QuotationSettings', [
            'organization' => $organization->only(['id', 'name']),
            'settings' => data_get($organization->settings, 'quotation_profile', []),
            'resolved' => $settings->settings($organization),
            'defaultAccentColor' => DocumentSellerProfile::DEFAULT_ACCENT_COLOR,
        ]);
    }

    public function update(Request $request, ActiveTenantContext $context, AuditLogger $audit): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $data = $request->validate([
            'default_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'default_terms' => ['nullable', 'string', 'max:5000'],
            'default_notes' => ['nullable', 'string', 'max:5000'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $settings = $organization->settings ?? [];
        $settings['quotation_profile'] = [
            'default_validity_days' => $data['default_validity_days'],
            'default_terms' => $data['default_terms'] ?? null,
            'default_notes' => $data['default_notes'] ?? null,
            'footer_text' => $data['footer_text'] ?? null,
            'accent_color' => isset($data['accent_color']) ? strtolower($data['accent_color']) : null,
        ];
        $organization->settings = $settings;
        $organization->save();

        $audit->record('document_profile.updated', $request->user(), $organization, $context->store(), $organization, newValues: [
            'section' => 'quotation_profile',
            'updated_fields' => array_keys($data),
        ]);

        return back()->with('success', 'Paramètres Devis enregistrés. Les prochains devis les utiliseront.');
    }
}
