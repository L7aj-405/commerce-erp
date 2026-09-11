<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Store;

/**
 * Devis-specific document options (Paramètres → Documents → Devis), stored on
 * `organization.settings.quotation_profile`. The company identity itself (logo,
 * legal name, address, ICE, RC, IF, TP, bank, RIB, …) is NOT duplicated — it is
 * read from the shared DocumentSellerProfile. This service only layers the
 * Devis-only overrides (accent colour, footer wording) and defaults
 * (validity period, terms, notes) on top.
 */
class QuotationDocumentSettings
{
    public function __construct(private readonly DocumentSellerProfile $sellerProfile) {}

    /** @return array<string, mixed> */
    public function settings(Organization $organization): array
    {
        $profile = data_get($organization->settings, 'quotation_profile', []);

        return [
            'default_validity_days' => (int) ($this->value($profile, 'default_validity_days')
                ?? config('documents.quotation.default_validity_days', 30)),
            'default_terms' => $this->value($profile, 'default_terms') ?? config('documents.quotation.default_terms'),
            'default_notes' => $this->value($profile, 'default_notes') ?? config('documents.quotation.default_notes'),
            'footer_text' => $this->value($profile, 'footer_text') ?? config('documents.quotation.footer_text'),
            'accent_color' => $this->accentColor($profile),
        ];
    }

    /**
     * Immutable seller/document snapshot frozen onto a Devis at draft creation.
     * A later Settings change never mutates it.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Organization $organization, Store $store): array
    {
        $seller = $this->sellerProfile->snapshot($organization, $store);
        $settings = $this->settings($organization);

        if ($settings['accent_color'] !== null) {
            $seller['accent_color'] = $settings['accent_color'];
        }
        if ($settings['footer_text'] !== null) {
            $seller['footer_text'] = $settings['footer_text'];
        }
        $seller['document_kind'] = 'quotation';

        return $seller;
    }

    /** @param array<string, mixed> $profile */
    private function accentColor(array $profile): ?string
    {
        $value = strtolower(trim((string) ($profile['accent_color'] ?? '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $profile */
    private function value(array $profile, string $key): mixed
    {
        $value = $profile[$key] ?? null;
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
