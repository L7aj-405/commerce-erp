<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentSellerProfile
{
    /** Accent colour applied to the document title, table headers and small accents. */
    public const DEFAULT_ACCENT_COLOR = '#2b3a30';

    /**
     * Freeze the seller identity into an immutable, self-contained array.
     *
     * Everything a document ever needs to render is captured here at draft
     * creation, including the logo embedded as a base64 data URI. Renderers read
     * this snapshot and never the live profile or the logo file, so an issued
     * invoice keeps rendering identically even if the logo is later replaced or
     * deleted.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Organization $organization, Store $store): array
    {
        $organizationProfile = data_get($organization->settings, 'document_profile', []);
        $storeProfile = data_get($store->settings, 'document_profile', []);

        return [
            'legal_name' => $this->value($organizationProfile, 'legal_name') ?: $organization->name,
            'trade_name' => $this->value($organizationProfile, 'trade_name'),
            'address' => $this->value($organizationProfile, 'address'),
            'phone' => $this->value($organizationProfile, 'phone'),
            'fax' => $this->value($organizationProfile, 'fax'),
            'email' => $this->value($organizationProfile, 'email'),
            'tax_identifier' => $this->value($organizationProfile, 'tax_identifier'),
            'registration_number' => $this->value($organizationProfile, 'registration_number'),
            'patente_number' => $this->value($organizationProfile, 'patente_number'),
            'website' => $this->value($organizationProfile, 'website'),
            'bank' => [
                'name' => $this->value($organizationProfile, 'bank_name'),
                'rib' => $this->value($organizationProfile, 'bank_rib'),
            ],
            'footer_text' => $this->value($organizationProfile, 'footer_text'),
            'accent_color' => $this->accentColor($organizationProfile),
            'logo' => $this->embedLogo($organizationProfile),
            'additional_identifiers' => collect($organizationProfile['additional_identifiers'] ?? [])->map(fn ($item) => [
                'label' => trim((string) ($item['label'] ?? '')),
                'value' => trim((string) ($item['value'] ?? '')),
            ])->filter(fn ($item) => $item['label'] !== '' && $item['value'] !== '')->values()->all(),
            'store' => [
                'name' => $store->name,
                'code' => $store->code,
                'address' => $this->value($storeProfile, 'address'),
                'phone' => $this->value($storeProfile, 'phone'),
                'email' => $this->value($storeProfile, 'email'),
            ],
        ];
    }

    /**
     * The organisation identity block only (no store sub-block), for internal
     * logistics documents such as the Bon de sortie which are not tied to a
     * store. Read from the current profile — a warehouse paper document is not a
     * historical financial record.
     *
     * @return array<string, mixed>
     */
    public function organizationIdentity(Organization $organization): array
    {
        $profile = data_get($organization->settings, 'document_profile', []);

        return [
            'legal_name' => $this->value($profile, 'legal_name') ?: $organization->name,
            'trade_name' => $this->value($profile, 'trade_name'),
            'address' => $this->value($profile, 'address'),
            'phone' => $this->value($profile, 'phone'),
            'email' => $this->value($profile, 'email'),
            'tax_identifier' => $this->value($profile, 'tax_identifier'),
            'registration_number' => $this->value($profile, 'registration_number'),
            'accent_color' => $this->accentColor($profile),
            'logo' => $this->embedLogo($profile),
        ];
    }

    /** @param array<string, mixed>|null $snapshot */
    public function validate(?array $snapshot): void
    {
        if (trim((string) ($snapshot['legal_name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'seller_profile' => 'The document seller legal name must be configured before issuance.',
            ]);
        }
    }

    /** @param array<string, mixed> $profile */
    private function accentColor(array $profile): string
    {
        $value = strtolower(trim((string) ($profile['accent_color'] ?? '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : self::DEFAULT_ACCENT_COLOR;
    }

    /**
     * Read the currently configured logo and return it as a `data:` URI so the
     * snapshot is portable and the PDF renderer needs no filesystem access.
     *
     * @param  array<string, mixed>  $profile
     */
    private function embedLogo(array $profile): ?string
    {
        $path = trim((string) ($profile['logo_path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
        if ($mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($path));
    }

    /** @param array<string, mixed> $profile */
    private function value(array $profile, string $key): ?string
    {
        $value = trim((string) ($profile[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
