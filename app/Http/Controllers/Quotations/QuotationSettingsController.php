<?php

namespace App\Http\Controllers\Quotations;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\QuotationDocumentSettings;
use App\Services\QuotationNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function edit(Request $request, ActiveTenantContext $context, QuotationDocumentSettings $settings, QuotationNumberGenerator $quotationNumbers): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);
        $quotationNumberingYear = now()->year;

        return Inertia::render('Settings/QuotationSettings', [
            'organization' => $organization->only(['id', 'name']),
            'settings' => data_get($organization->settings, 'quotation_profile', []),
            'resolved' => $settings->settings($organization),
            'quotationNumbering' => $quotationNumbers->settings($organization, $quotationNumberingYear),
            'defaultAccentColor' => DocumentSellerProfile::DEFAULT_ACCENT_COLOR,
            'canUpdate' => $request->user()->hasPermission($organization, 'settings.update'),
        ]);
    }

    public function update(Request $request, ActiveTenantContext $context, AuditLogger $audit, QuotationNumberGenerator $quotationNumbers): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $data = $request->validate([
            'default_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'default_terms' => ['nullable', 'string', 'max:5000'],
            'default_notes' => ['nullable', 'string', 'max:5000'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'quotation_numbering_year' => ['nullable', 'required_with:quotation_next_number', 'integer', 'min:2000', 'max:2100'],
            'quotation_next_number' => ['nullable', 'required_with:quotation_numbering_year', 'integer', 'min:1'],
        ]);

        $settings = $organization->settings ?? [];
        DB::transaction(function () use ($settings, $organization, $data, $quotationNumbers, $audit, $request, $context) {
            $settings['quotation_profile'] = [
                'default_validity_days' => $data['default_validity_days'],
                'default_terms' => $data['default_terms'] ?? null,
                'default_notes' => $data['default_notes'] ?? null,
                'footer_text' => $data['footer_text'] ?? null,
                'accent_color' => isset($data['accent_color']) ? strtolower($data['accent_color']) : null,
            ];
            $organization->settings = $settings;
            $organization->save();

            if (isset($data['quotation_numbering_year'], $data['quotation_next_number'])) {
                $quotationNumbers->configureNextNumber($organization, (int) $data['quotation_numbering_year'], (int) $data['quotation_next_number']);
            }

            $audit->record('document_profile.updated', $request->user(), $organization, $context->store(), $organization, newValues: [
                'section' => 'quotation_profile',
                'updated_fields' => array_keys($data),
                'quotation_numbering_year' => isset($data['quotation_numbering_year']) ? (int) $data['quotation_numbering_year'] : null,
                'quotation_next_number' => isset($data['quotation_next_number']) ? (int) $data['quotation_next_number'] : null,
            ]);
        });

        return back()->with('success', 'Paramètres Devis enregistrés. Les prochains devis les utiliseront.');
    }
}
