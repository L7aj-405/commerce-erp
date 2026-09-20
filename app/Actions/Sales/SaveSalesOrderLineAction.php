<?php

namespace App\Actions\Sales;

use App\Actions\Sales\Concerns\AuthorizesSalesAction;
use App\Enums\CatalogStatus;
use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use App\Enums\WarehouseStatus;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderInventoryAllocation;
use App\Models\SalesOrderLine;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use App\Services\PosStockAllocator;
use App\Services\PriceCalculator;
use App\Services\ProductPriceResolver;
use App\Services\SalesLineCalculator;
use App\Services\SalesOrderTotalsCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveSalesOrderLineAction
{
    use AuthorizesSalesAction;

    public function __construct(
        private readonly SalesLineCalculator $calculator,
        private readonly SalesOrderTotalsCalculator $totals,
        private readonly AuditLogger $audit,
        private readonly PosStockAllocator $posStock,
        private readonly ProductPriceResolver $priceResolver,
        private readonly PriceCalculator $prices,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, array $data, ?SalesOrderLine $line = null): SalesOrderLine
    {
        $this->authorizeOrder($actor, $order, 'sales_orders.update');

        return DB::transaction(function () use ($actor, $order, $data, $line) {
            $order = SalesOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (! $order->isCommerciallyEditable()) {
                throw ValidationException::withMessages(['order' => 'Lines cannot be changed after confirmation.']);
            }
            if ($line) {
                $line = SalesOrderLine::query()->where('organization_id', $order->organization_id)
                    ->where('sales_order_id', $order->getKey())->whereKey($line->getKey())->firstOrFail();
            }
            $type = SalesOrderLineType::from($data['line_type']);
            $discountType = SalesOrderDiscountType::from($data['discount_type'] ?? SalesOrderDiscountType::None->value);
            if ($discountType !== SalesOrderDiscountType::None && ! $actor->hasPermission($order->organization_id, 'sales_orders.apply_discount')) {
                abort(403);
            }

            [$snapshot, $defaultPrice, $taxRate, $taxName, $taxUnresolved, $warehouse, $variant] = $type === SalesOrderLineType::Catalog
                ? $this->catalog($order, $data, $line)
                : $this->custom($order, $data);
            $unitPrice = $data['unit_price_excl_tax'] ?? $defaultPrice;
            if ($type === SalesOrderLineType::Catalog && Decimal::compare($unitPrice, $defaultPrice) !== 0) {
                abort_unless($actor->hasPermission($order->organization_id, 'sales_orders.override_price'), 403);
                $this->audit->record('sales_order.price_overridden', $actor, $order->organization, $order->store, $order, newValues: [
                    'order_number' => $order->order_number, 'product_variant_id' => $snapshot['product_variant_id'],
                    'default_price' => $defaultPrice, 'override_price' => Decimal::normalize($unitPrice),
                ]);
            }
            $calculated = $this->calculator->calculate($data['quantity'], $unitPrice, $taxRate, $discountType, $data['discount_value'] ?? '0');
            if ($discountType !== SalesOrderDiscountType::None) {
                $this->audit->record('sales_order.discount_applied', $actor, $order->organization, $order->store, $order, newValues: [
                    'order_number' => $order->order_number, 'discount_type' => $discountType->value,
                    'discount_value' => $calculated['discount_value'], 'discount_amount' => $calculated['discount_amount'],
                ]);
            }

            $line ??= new SalesOrderLine;
            $line->organization_id = $order->organization_id;
            $line->sales_order_id = $order->getKey();
            $line->line_type = $type;
            $line->position ??= ((int) $order->lines()->max('position')) + 1;
            foreach ($snapshot as $field => $value) {
                $line->{$field} = $value;
            }
            $line->tax_name = $taxName;
            $line->tax_unresolved = $taxUnresolved;
            $line->discount_type = $discountType;
            foreach ($calculated as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();
            $line->allocations()->delete();
            if ($warehouse) {
                // Draft sourcing is always provisional: any company-stock
                // shortfall is parked on the operational warehouse and covers
                // the full line quantity. The authoritative, locked rebuild —
                // and the strict company-stock shortage check — happens at
                // confirmation, where a shortfall is either allocated from
                // company stock or covered by a confirmed supplier procurement.
                // POS instant checkout re-verifies coverage before it confirms,
                // so a genuine backorder cannot be sold on the spot.
                $allocations = $this->posStock->planProvisional($order, $warehouse, $variant, $calculated['quantity'], $line);

                foreach ($allocations as $prepared) {
                    $allocation = new SalesOrderInventoryAllocation;
                    $allocation->organization_id = $order->organization_id;
                    $allocation->sales_order_line_id = $line->getKey();
                    $allocation->warehouse_id = $prepared['warehouse']->getKey();
                    $allocation->quantity = $prepared['quantity'];
                    $allocation->save();
                }
            }
            $this->totals->recalculate($order);

            return $line->load('allocations.warehouse');
        });
    }

    /** @return array{array<string, mixed>, string, string, ?string, bool, Warehouse, ProductVariant} */
    private function catalog(SalesOrder $order, array $data, ?SalesOrderLine $line): array
    {
        $variant = ProductVariant::query()->where('organization_id', $order->organization_id)->whereKey($data['product_variant_id'])
            ->with(['product.defaultUnit', 'taxRate'])->firstOrFail();
        if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['product_variant_id' => 'Only active catalog variants may be added.']);
        }
        $warehouse = $this->resolveWarehouse($order, $variant, $data, $line);

        // Resolve the effective tax rate + tax-exclusive unit price once, from the
        // Product override or the Store / organisation default. This is what gets
        // snapshotted onto the line and is never recomputed afterwards.
        $price = $this->priceResolver->resolve($variant, $order->store);

        $taxUnresolved = $price['config_missing'];
        $taxRateValue = $price['tax_rate_value'];
        $taxName = $price['tax_name'];
        $unitHt = $price['unit_price_ht'];

        if ($taxUnresolved) {
            // No exploitable HT/tax split (e.g. a public TTC price with no tax
            // rate anywhere). The line stays SELECTABLE on the Draft with an
            // EXPLICIT unresolved-tax marker — never a silent 0%. Choosing a
            // TaxRate on the line derives the real HT from the public price.
            $publicTtc = $price['unit_price_ttc']
                ?? ($variant->default_sale_price !== null ? Decimal::normalize((string) $variant->default_sale_price) : null);

            if (! empty($data['tax_rate_id'])) {
                $chosen = TaxRate::query()->where('organization_id', $order->organization_id)
                    ->whereKey($data['tax_rate_id'])->firstOrFail();
                if ($chosen->status !== CatalogStatus::Active) {
                    throw ValidationException::withMessages(['tax_rate_id' => 'Only an active tax rate may be selected.']);
                }
                if ($publicTtc === null) {
                    throw ValidationException::withMessages(['product_variant_id' => 'Cet article n’a aucun prix exploitable.']);
                }
                $taxRateValue = Decimal::normalize((string) $chosen->rate);
                $taxName = $chosen->name;
                $unitHt = $this->prices->exclusive($publicTtc, $taxRateValue);
                $taxUnresolved = false;
            } else {
                // Provisional HT = the public price (a real figure, recomputed
                // once a tax is chosen). tax_rate is 0 but tax_unresolved keeps
                // it distinct from a genuinely configured 0% TaxRate.
                $unitHt = $publicTtc;
                $taxRateValue = '0.0000';
                $taxName = null;
            }
        }

        if ($unitHt === null) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Cet article n’a aucun prix exploitable. Renseignez un prix avant de l’ajouter.',
            ]);
        }

        return [[
            'product_variant_id' => $variant->getKey(), 'product_name' => $variant->product->name,
            'variant_name' => $variant->label, 'sku' => $variant->sku, 'reference' => $variant->reference,
            'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
        ], $unitHt, $taxRateValue, $taxName, $taxUnresolved, $warehouse, $variant];
    }

    /**
     * The operator no longer picks a warehouse per line in the modern editor.
     * An explicit `warehouse_id` (POS, Devis conversion, legacy callers) still
     * wins; editing a line keeps its current source; otherwise a deterministic
     * same-organization default is chosen from available stock. Never crosses
     * organizations.
     */
    private function resolveWarehouse(SalesOrder $order, ProductVariant $variant, array $data, ?SalesOrderLine $line): Warehouse
    {
        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::query()->where('organization_id', $order->organization_id)->whereKey($data['warehouse_id'])->firstOrFail();
            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages(['warehouse_id' => 'Only an active warehouse may be allocated.']);
            }

            return $warehouse;
        }

        $current = $line?->allocations()->with('warehouse')->first()?->warehouse;
        if ($current && $current->status === WarehouseStatus::Active && (int) $current->organization_id === (int) $order->organization_id) {
            return $current;
        }

        $warehouse = $this->posStock->preferredWarehouse($order, $variant);
        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Aucun entrepôt actif n’est disponible pour cette organisation.',
            ]);
        }

        return $warehouse;
    }

    /** @return array{array<string, mixed>, string, string, ?string, bool, null, null} */
    private function custom(SalesOrder $order, array $data): array
    {
        $tax = ! empty($data['tax_rate_id'])
            ? TaxRate::query()->where('organization_id', $order->organization_id)->whereKey($data['tax_rate_id'])->firstOrFail()
            : null;
        if ($tax && $tax->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages(['tax_rate_id' => 'Only an active tax rate may be selected.']);
        }
        $rate = Decimal::normalize((string) ($tax?->rate ?? '0'));

        // Price entry, Devis-style: either an explicit HT (`unit_price_excl_tax` —
        // legacy callers + Devis conversion) or a mode + single amount
        // (`price_input_mode` = ht|ttc, `unit_price`). A TTC amount is converted
        // to HT here with the exact-decimal tax engine — never a hardcoded rate,
        // never two contradictory values from the client.
        $hasMode = array_key_exists('price_input_mode', $data) && filled($data['price_input_mode']);
        $hasLegacy = array_key_exists('unit_price_excl_tax', $data) && filled($data['unit_price_excl_tax']);
        if (! $hasMode && ! $hasLegacy) {
            throw ValidationException::withMessages(['unit_price' => 'Un prix est requis pour un article personnalisé.']);
        }

        if ($hasMode) {
            $entered = Decimal::normalize((string) ($data['unit_price'] ?? '0'));
            $ht = $data['price_input_mode'] === 'ttc' ? $this->prices->exclusive($entered, $rate) : $entered;
        } else {
            $ht = Decimal::normalize((string) $data['unit_price_excl_tax']);
        }

        return [[
            'product_variant_id' => null, 'product_name' => $data['description'], 'variant_name' => null,
            'sku' => null, 'reference' => $data['reference'] ?? null, 'unit_label' => $data['unit_label'] ?? null,
        ], $ht, $rate, $tax?->name, false, null, null];
    }
}
