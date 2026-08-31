<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Store;
use Illuminate\Validation\ValidationException;

class DocumentSellerProfile
{
    /** @return array<string, mixed> */
    public function snapshot(Organization $organization, Store $store): array
    {
        $organizationProfile = data_get($organization->settings, 'document_profile', []);
        $storeProfile = data_get($store->settings, 'document_profile', []);

        return [
            'legal_name' => $this->value($organizationProfile, 'legal_name') ?: $organization->name,
            'trade_name' => $this->value($organizationProfile, 'trade_name'),
            'address' => $this->value($organizationProfile, 'address'),
            'phone' => $this->value($organizationProfile, 'phone'),
            'email' => $this->value($organizationProfile, 'email'),
            'tax_identifier' => $this->value($organizationProfile, 'tax_identifier'),
            'registration_number' => $this->value($organizationProfile, 'registration_number'),
            'website' => $this->value($organizationProfile, 'website'),
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
    private function value(array $profile, string $key): ?string
    {
        $value = trim((string) ($profile[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
