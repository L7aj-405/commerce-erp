<?php

namespace App\Services;

use App\Enums\PriceInputMode;
use App\Enums\QuotationLineType;
use App\Enums\SalesOrderDiscountType;
use App\Models\NonStockItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Resolves and calculates ONE Devis line, server-side and authoritative.
 *
 * Pipeline (identical maths to Sales / Invoice):
 *   identity + tax + price basis  ->  effective HT unit price  ->
 *   SalesLineCalculator (gross HT -> discount -> taxable HT -> TVA -> TTC)
 *
 * HT and TTC never become independent editable values: the employee picks a
 * `price_input_mode` (HT | TTC) and enters ONE number; the counterpart is
 * always derived here with exact Decimal arithmetic (never "TTC - 20%", never a
 * hardcoded 20%).
 */
class QuotationLinePricing
{
    public function __construct(
        private readonly ProductPriceResolver $priceResolver,
        private readonly PriceCalculator $prices,
        private readonly SalesLineCalculator $calculator,
    ) {}

    /**
     * @param  array{quantity: string, price_input_mode?: string|null, unit_price?: string|null, discount_type?: string|null, discount_value?: string|null}  $input
     * @return array<string, mixed>  Column set ready to persist on quotation_lines (identity + calculated money fields).
     */
    public function forCatalog(ProductVariant $variant, ?Store $store, array $input): array
    {
        $price = $this->priceResolver->resolve($variant, $store);
        if ($price['config_missing']) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Impossible de calculer le prix HT : aucune taxe par défaut n’est configurée pour ce magasin.',
            ]);
        }

        return $this->assemble([
            'line_type' => QuotationLineType::Catalog,
            'product_variant_id' => $variant->getKey(),
            'non_stock_item_id' => null,
            'description' => $variant->product->name,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->label,
            'sku' => $variant->sku,
            'reference' => $variant->reference,
            'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
            'tax_name' => $price['tax_name'],
            'tax_rate' => $price['tax_rate_value'],
            'default_ht' => $price['unit_price_ht'],
            'default_ttc' => $price['unit_price_ttc'] ?? $this->prices->inclusive($price['unit_price_ht'] ?? '0', $price['tax_rate_value']),
        ], $input, defaultMode: PriceInputMode::Ht);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function forNonStockItem(NonStockItem $item, array $input): array
    {
        $mode = $item->price_input_mode ?? PriceInputMode::Ht;
        $defaultHt = $item->default_price_excl_tax;
        $defaultTtc = $item->default_price_incl_tax;
        if ($defaultHt === null && $defaultTtc !== null) {
            $defaultHt = $this->prices->exclusive($defaultTtc, $item->tax_rate);
        }
        if ($defaultTtc === null && $defaultHt !== null) {
            $defaultTtc = $this->prices->inclusive($defaultHt, $item->tax_rate);
        }

        return $this->assemble([
            'line_type' => QuotationLineType::NonStock,
            'product_variant_id' => null,
            'non_stock_item_id' => $item->getKey(),
            'description' => $item->name,
            'product_name' => $item->name,
            'variant_name' => null,
            'sku' => null,
            'reference' => $item->reference,
            'unit_label' => $item->unit_label,
            'tax_name' => $item->tax_name,
            'tax_rate' => Decimal::normalize((string) $item->tax_rate),
            'default_ht' => $defaultHt !== null ? Decimal::normalize((string) $defaultHt) : null,
            'default_ttc' => $defaultTtc !== null ? Decimal::normalize((string) $defaultTtc) : null,
        ], $input, defaultMode: $mode);
    }

    /**
     * A one-off non-stock line whose article is NOT persisted to the library
     * (used internally when the library row is created alongside — see
     * SaveQuotationLineAction). `$source` carries name/reference/unit + resolved
     * tax + default prices.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function forRawNonStock(array $source, array $input): array
    {
        return $this->assemble([
            'line_type' => QuotationLineType::NonStock,
            'product_variant_id' => null,
            'non_stock_item_id' => $source['non_stock_item_id'] ?? null,
            'description' => $source['name'],
            'product_name' => $source['name'],
            'variant_name' => null,
            'sku' => null,
            'reference' => $source['reference'] ?? null,
            'unit_label' => $source['unit_label'] ?? null,
            'tax_name' => $source['tax_name'] ?? null,
            'tax_rate' => Decimal::normalize((string) ($source['tax_rate'] ?? '0')),
            'default_ht' => isset($source['default_ht']) ? Decimal::normalize((string) $source['default_ht']) : null,
            'default_ttc' => isset($source['default_ttc']) ? Decimal::normalize((string) $source['default_ttc']) : null,
        ], $input, defaultMode: PriceInputMode::from($source['price_input_mode'] ?? 'ht'));
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function assemble(array $identity, array $input, PriceInputMode $defaultMode): array
    {
        $taxRate = Decimal::nonNegative((string) $identity['tax_rate'], 'tax_rate');
        if (Decimal::compare($taxRate, '100.0000') > 0) {
            throw ValidationException::withMessages(['tax_rate' => 'Le taux de TVA ne peut pas dépasser 100%.']);
        }

        $mode = isset($input['price_input_mode']) && $input['price_input_mode'] !== null && $input['price_input_mode'] !== ''
            ? PriceInputMode::from($input['price_input_mode'])
            : $defaultMode;

        $entered = $input['unit_price'] ?? null;
        $entered = $entered === null || $entered === '' ? null : Decimal::nonNegative((string) $entered, 'unit_price');

        if ($entered === null) {
            // No explicit price: fall back to the resolved default in its own mode.
            $unitHt = $identity['default_ht'];
            if ($unitHt === null && $identity['default_ttc'] !== null) {
                $unitHt = $this->prices->exclusive($identity['default_ttc'], $taxRate);
                $mode = PriceInputMode::Ttc;
            }
            if ($unitHt === null) {
                throw ValidationException::withMessages(['unit_price' => 'Un prix unitaire est requis pour cet article.']);
            }
            $unitHt = Decimal::normalize((string) $unitHt);
            if ($mode === PriceInputMode::Ht && $identity['default_ht'] !== null) {
                $mode = PriceInputMode::Ht;
            }
        } else {
            $unitHt = $mode === PriceInputMode::Ttc
                ? $this->prices->exclusive($entered, $taxRate)
                : $entered;
        }

        $discountType = SalesOrderDiscountType::from($input['discount_type'] ?? SalesOrderDiscountType::None->value);
        $calc = $this->calculator->calculate(
            (string) $input['quantity'],
            $unitHt,
            $taxRate,
            $discountType,
            $input['discount_value'] ?? '0',
        );

        return [
            'line_type' => $identity['line_type'],
            'product_variant_id' => $identity['product_variant_id'],
            'non_stock_item_id' => $identity['non_stock_item_id'],
            'description' => $identity['description'],
            'product_name' => $identity['product_name'],
            'variant_name' => $identity['variant_name'],
            'sku' => $identity['sku'],
            'reference' => $identity['reference'],
            'unit_label' => $identity['unit_label'],
            'tax_name' => $identity['tax_name'],
            'price_input_mode' => $mode,
            'discount_type' => $discountType,
            // SalesLineCalculator output (quantity, unit_price_excl_tax,
            // unit_price_incl_tax, tax_rate, discount_value, subtotal_excl_tax,
            // discount_amount, taxable_amount, tax_amount, total_incl_tax).
            ...$calc,
        ];
    }
}
