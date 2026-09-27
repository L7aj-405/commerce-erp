<?php

namespace App\Support;

class LegalMetadata
{
    /** @return array<string, string|null> */
    public static function props(): array
    {
        return [
            'productName' => (string) config('legal.product_name', '10xScale ERP'),
            'operatorName' => config('legal.operator_name') ?: null,
            'contactEmail' => config('legal.contact_email') ?: null,
            'lastUpdated' => (string) config('legal.last_updated', '2026-09-27'),
            'productionUrl' => (string) config('legal.production_url', config('app.url')),
        ];
    }

    /** @return array<string, string> */
    public static function links(): array
    {
        return [
            'privacy' => route('privacy'),
            'terms' => route('terms'),
        ];
    }
}
