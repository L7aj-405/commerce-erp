<?php

namespace App\Services;

use App\Enums\CatalogStatus;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\TaxRate;
use App\Support\Decimal;

/**
 * Resolves the EFFECTIVE tax rate and tax-exclusive (HT) unit price for a
 * Product variant, without ever mutating stored data.
 *
 * Tax rate resolution order (never hardcodes a rate):
 *   1. the variant's own tax_rate_id
 *   2. the active Store's default_tax_rate_id
 *   3. the organisation's default (is_default) Tax Rate
 *   4. none
 *
 * HT resolution:
 *   - if `unit_price_ht` is stored -> use it verbatim  (ht_source = "stored")
 *   - else derive from the public price: HT = TTC / (1 + rate/100)  ("derived")
 *   - if the HT must be derived but no tax rate is resolvable -> HT is null and
 *     `config_missing` is true (callers that need HT for a financial finalisation
 *     must fail loudly rather than record a wrong value).
 *
 * The public (TTC) price is `public_price_ttc` when set, otherwise the legacy
 * `default_sale_price` (treated as the customer-facing price).
 */
class ProductPriceResolver
{
    public function __construct(private readonly PriceCalculator $prices) {}

    /**
     * @return array{
     *   tax_rate_id: int|null, tax_name: string|null, tax_rate_value: string,
     *   tax_source: string, unit_price_ht: string|null, unit_price_ttc: string|null,
     *   ht_source: string, config_missing: bool
     * }
     */
    public function resolve(ProductVariant $variant, ?Store $store): array
    {
        return $this->resolveWith($variant, $this->defaultTaxRate($store, (int) $variant->organization_id));
    }

    /**
     * Same as resolve() but with the Store/organisation default tax rate already
     * looked up once — for endpoints that resolve a whole page of variants.
     *
     * @return array<string, mixed>
     */
    public function resolveWith(ProductVariant $variant, ?TaxRate $defaultTaxRate): array
    {
        $productTax = $variant->relationLoaded('taxRate') ? $variant->taxRate : $variant->taxRate()->getResults();

        $taxRate = $productTax;
        $taxSource = $productTax ? 'product' : null;
        if (! $taxRate && $defaultTaxRate) {
            $taxRate = $defaultTaxRate;
            $taxSource = 'store';
        }

        $rateValue = $taxRate ? Decimal::normalize((string) $taxRate->rate) : '0.0000';
        $storedHt = $this->stored($variant->unit_price_ht);
        $publicTtc = $this->stored($variant->public_price_ttc) ?? $this->stored($variant->default_sale_price);

        if ($storedHt !== null) {
            $ht = $storedHt;
            $htSource = 'stored';
            $ttc = $publicTtc ?? $this->prices->inclusive($ht, $rateValue);
        } elseif ($publicTtc !== null && $taxRate !== null) {
            $ht = $this->prices->exclusive($publicTtc, $rateValue);
            $htSource = 'derived';
            $ttc = $publicTtc;
        } else {
            // Public price but no explicit HT and no tax rate anywhere: the HT / VAT
            // split cannot be trusted. Keep the TTC for display, flag the gap.
            $ht = null;
            $htSource = 'unknown';
            $ttc = $publicTtc;
        }

        return [
            'tax_rate_id' => $taxRate?->getKey(),
            'tax_name' => $taxRate?->name,
            'tax_rate_value' => $rateValue,
            'tax_source' => $taxSource ?? 'none',
            'unit_price_ht' => $ht,
            'unit_price_ttc' => $ttc,
            'ht_source' => $htSource,
            'config_missing' => $ht === null,
        ];
    }

    public function defaultTaxRate(?Store $store, int $organizationId): ?TaxRate
    {
        if ($store && $store->default_tax_rate_id) {
            $rate = $store->relationLoaded('defaultTaxRate') ? $store->defaultTaxRate : $store->defaultTaxRate()->getResults();
            if ($rate && $rate->status === CatalogStatus::Active) {
                return $rate;
            }
        }

        return TaxRate::query()
            ->where('organization_id', $organizationId)
            ->where('is_default', true)
            ->where('status', CatalogStatus::Active->value)
            ->first();
    }

    private function stored(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Decimal::normalize((string) $value);
    }
}
