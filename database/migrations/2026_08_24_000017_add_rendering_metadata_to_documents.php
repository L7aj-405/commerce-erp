<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('seller_snapshot')->nullable();
            $table->string('template_version', 32)->default('v1');
        });
        Schema::table('delivery_notes', function (Blueprint $table) {
            $table->json('seller_snapshot')->nullable();
            $table->string('template_version', 32)->default('v1');
        });

        foreach (['invoices', 'delivery_notes'] as $table) {
            DB::table($table)->orderBy('id')->eachById(function ($document) use ($table) {
                $organization = DB::table('organizations')->where('id', $document->organization_id)->first();
                $store = DB::table('stores')->where('id', $document->store_id)->first();

                DB::table($table)->where('id', $document->id)->update([
                    'seller_snapshot' => json_encode($this->snapshot($organization, $store), JSON_THROW_ON_ERROR),
                    'template_version' => 'v1',
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('delivery_notes', fn (Blueprint $table) => $table->dropColumn(['seller_snapshot', 'template_version']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['seller_snapshot', 'template_version']));
    }

    /** @return array<string, mixed> */
    private function snapshot(?object $organization, ?object $store): array
    {
        $organizationSettings = $this->settings($organization?->settings ?? null);
        $storeSettings = $this->settings($store?->settings ?? null);
        $profile = is_array($organizationSettings['document_profile'] ?? null) ? $organizationSettings['document_profile'] : [];
        $storeProfile = is_array($storeSettings['document_profile'] ?? null) ? $storeSettings['document_profile'] : [];

        return [
            'legal_name' => $this->value($profile, 'legal_name') ?: (string) ($organization?->name ?? 'Unknown organization'),
            'trade_name' => $this->value($profile, 'trade_name'),
            'address' => $this->value($profile, 'address'),
            'phone' => $this->value($profile, 'phone'),
            'email' => $this->value($profile, 'email'),
            'tax_identifier' => $this->value($profile, 'tax_identifier'),
            'registration_number' => $this->value($profile, 'registration_number'),
            'website' => $this->value($profile, 'website'),
            'additional_identifiers' => array_values(array_filter(array_map(fn ($item) => [
                'label' => trim((string) ($item['label'] ?? '')),
                'value' => trim((string) ($item['value'] ?? '')),
            ], is_array($profile['additional_identifiers'] ?? null) ? $profile['additional_identifiers'] : []), fn ($item) => $item['label'] !== '' && $item['value'] !== '')),
            'store' => [
                'name' => (string) ($store?->name ?? ''),
                'code' => (string) ($store?->code ?? ''),
                'address' => $this->value($storeProfile, 'address'),
                'phone' => $this->value($storeProfile, 'phone'),
                'email' => $this->value($storeProfile, 'email'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function settings(mixed $settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }

        $decoded = json_decode((string) $settings, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $profile */
    private function value(array $profile, string $key): ?string
    {
        $value = trim((string) ($profile[$key] ?? ''));

        return $value === '' ? null : $value;
    }
};
