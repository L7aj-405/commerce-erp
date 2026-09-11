<?php

namespace App\Services\WooCommerce;

use App\Models\Organization;
use App\Models\Store;
use App\Models\TaxRate;
use App\Services\ProductPriceResolver;
use Illuminate\Support\Str;

/**
 * Maps a WooCommerce `tax_status` / `tax_class` to an ERP TaxRate.
 *
 *  - tax_status "none"                 -> no tax (deliberate, no warning)
 *  - tax_class "" / "standard"         -> the ERP Store (or organisation) default tax rate
 *  - any other class (e.g. "reduced-rate") -> matched to an ERP TaxRate by name
 *
 * An unresolved class returns a null tax rate AND a warning string, so the sync
 * records a diagnostic rather than silently assigning the wrong tax.
 */
class WooTaxClassResolver
{
    public function __construct(private readonly ProductPriceResolver $priceResolver) {}

    /**
     * @return array{tax_rate_id: int|null, warning: string|null}
     */
    public function resolve(string $taxStatus, ?string $taxClass, Organization $organization, ?Store $store): array
    {
        if ($taxStatus === 'none') {
            return ['tax_rate_id' => null, 'warning' => null];
        }

        $class = strtolower(trim((string) $taxClass));

        if ($class === '' || $class === 'standard') {
            $default = $this->priceResolver->defaultTaxRate($store, $organization->getKey());

            return $default
                ? ['tax_rate_id' => $default->getKey(), 'warning' => null]
                : ['tax_rate_id' => null, 'warning' => 'Aucune taxe par défaut n’est configurée pour ce magasin.'];
        }

        $slug = Str::slug($class);
        $match = TaxRate::query()
            ->where('organization_id', $organization->getKey())
            ->get(['id', 'name'])
            ->first(fn (TaxRate $rate) => Str::slug($rate->name) === $slug
                || Str::contains(Str::slug($rate->name), $slug)
                || Str::contains($slug, Str::slug($rate->name)));

        return $match
            ? ['tax_rate_id' => $match->getKey(), 'warning' => null]
            : ['tax_rate_id' => null, 'warning' => "Classe de taxe WooCommerce « {$taxClass} » non mappée à un taux ERP."];
    }
}
