<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Services\ActiveTenantContext;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class DocumentProfileController extends Controller
{
    public function edit(Request $request, ActiveTenantContext $context, InvoiceNumberGenerator $invoiceNumbers): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewSettings', $organization);

        $profile = data_get($organization->settings, 'document_profile', []);
        $logoPath = trim((string) ($profile['logo_path'] ?? ''));
        $invoiceNumberingYear = now()->year;

        return Inertia::render('Documents/Profile', [
            'organization' => $organization->only(['id', 'name']),
            'profile' => $profile,
            'invoiceNumbering' => $invoiceNumbers->settings($organization, $invoiceNumberingYear),
            'logoUrl' => $logoPath !== '' && Storage::disk('public')->exists($logoPath)
                ? Storage::disk('public')->url($logoPath)
                : null,
            'defaultAccentColor' => DocumentSellerProfile::DEFAULT_ACCENT_COLOR,
            'canUpdate' => $request->user()->hasPermission($organization, 'settings.update'),
        ]);
    }

    public function update(Request $request, ActiveTenantContext $context, AuditLogger $audit, InvoiceNumberGenerator $invoiceNumbers): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('updateSettings', $organization);

        $this->normalizeWebsiteInput($request);

        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'fax' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'tax_identifier' => ['nullable', 'string', 'max:128'],
            'registration_number' => ['nullable', 'string', 'max:128'],
            'patente_number' => ['nullable', 'string', 'max:128'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:128'],
            'bank_rib' => ['nullable', 'string', 'max:64'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'show_invoice_watermark' => ['nullable', 'boolean'],
            'invoice_numbering_year' => ['nullable', 'required_with:invoice_next_number', 'integer', 'min:2000', 'max:2100'],
            'invoice_next_number' => ['nullable', 'required_with:invoice_numbering_year', 'integer', 'min:1'],
            'additional_identifiers' => ['array', 'max:10'],
            'additional_identifiers.*.label' => ['required', 'string', 'max:64'],
            'additional_identifiers.*.value' => ['required', 'string', 'max:128'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $settings = $organization->settings ?? [];
        $profile = $settings['document_profile'] ?? [];
        $currentLogo = trim((string) ($profile['logo_path'] ?? ''));

        $profile = array_merge($profile, [
            'legal_name' => $data['legal_name'],
            'trade_name' => $data['trade_name'] ?? null,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'fax' => $data['fax'] ?? null,
            'email' => $data['email'] ?? null,
            'tax_identifier' => $data['tax_identifier'] ?? null,
            'registration_number' => $data['registration_number'] ?? null,
            'patente_number' => $data['patente_number'] ?? null,
            'website' => $data['website'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_rib' => $data['bank_rib'] ?? null,
            'footer_text' => $data['footer_text'] ?? null,
            'accent_color' => strtolower($data['accent_color'] ?? DocumentSellerProfile::DEFAULT_ACCENT_COLOR),
            'show_invoice_watermark' => $request->boolean('show_invoice_watermark'),
            'additional_identifiers' => array_values($data['additional_identifiers'] ?? []),
        ]);

        // Logo lifecycle. Issued invoices embed the logo in their own immutable
        // seller snapshot, so removing or replacing the file here never affects
        // documents that were already drafted or issued.
        if ($request->boolean('remove_logo')) {
            if ($currentLogo !== '') {
                Storage::disk('public')->delete($currentLogo);
            }
            $profile['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($currentLogo !== '') {
                Storage::disk('public')->delete($currentLogo);
            }
            $profile['logo_path'] = $request->file('logo')->store('document-profiles', 'public');
        }

        DB::transaction(function () use ($settings, $profile, $organization, $invoiceNumbers, $data, $audit, $request, $context) {
            $settings['document_profile'] = $profile;
            $organization->settings = $settings;
            $organization->save();

            if (isset($data['invoice_numbering_year'], $data['invoice_next_number'])) {
                $invoiceNumbers->configureNextNumber($organization, (int) $data['invoice_numbering_year'], (int) $data['invoice_next_number']);
            }

            $audit->record('document_profile.updated', $request->user(), $organization, $context->store(), $organization, newValues: [
                'updated_fields' => array_keys($data),
                'logo_changed' => $request->boolean('remove_logo') || $request->hasFile('logo'),
                'invoice_numbering_year' => isset($data['invoice_numbering_year']) ? (int) $data['invoice_numbering_year'] : null,
                'invoice_next_number' => isset($data['invoice_next_number']) ? (int) $data['invoice_next_number'] : null,
            ]);
        });

        return back()->with('success', 'Profil des documents enregistré. Les prochaines factures utiliseront ces informations.');
    }

    private function normalizeWebsiteInput(Request $request): void
    {
        if (! $request->has('website')) {
            return;
        }

        $website = trim((string) $request->input('website'));
        if ($website === '') {
            $request->merge(['website' => null]);

            return;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $website)) {
            $website = 'https://'.$website;
        }

        $request->merge(['website' => $website]);
    }
}
