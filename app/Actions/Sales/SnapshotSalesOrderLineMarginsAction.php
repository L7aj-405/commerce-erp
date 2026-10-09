<?php

namespace App\Actions\Sales;

use App\Enums\SalesOrderLineType;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\SaleLineMarginCalculator;
use Illuminate\Support\Collection;

final class SnapshotSalesOrderLineMarginsAction
{
    public function __construct(private readonly SaleLineMarginCalculator $calculator) {}

    /** @param Collection<int, SalesOrderLine> $lines */
    public function execute(SalesOrder $order, Collection $lines): void
    {
        $variantCosts = ProductVariant::query()
            ->where('organization_id', $order->organization_id)
            ->whereIn('id', $lines
                ->filter(fn (SalesOrderLine $line) => $line->cost_status === 'pending' && $line->line_type === SalesOrderLineType::Catalog)
                ->pluck('product_variant_id')
                ->filter())
            ->lockForUpdate()
            ->pluck('purchase_price', 'id');

        foreach ($lines as $line) {
            if ($line->cost_status === 'pending') {
                $snapshot = $line->line_type === SalesOrderLineType::Catalog
                    ? $this->calculator->calculate(
                        (string) $line->taxable_amount,
                        (string) $line->quantity,
                        $variantCosts->has($line->product_variant_id) ? $variantCosts->get($line->product_variant_id) : null,
                    )
                    : $this->calculator->unavailable();
            } elseif ($line->cost_status === 'available') {
                // Commercial corrections preserve the committed unit cost while
                // recalculating margin from the corrected quantity/net HT.
                $snapshot = $this->calculator->calculate(
                    (string) $line->taxable_amount,
                    (string) $line->quantity,
                    $line->purchase_price_snapshot,
                );
            } elseif ($line->cost_status === null) {
                // A pre-C2 line has no trustworthy historical cost source. Never
                // backfill it from today's catalog cost during a later correction.
                $snapshot = $this->calculator->unavailable();
            } else {
                continue;
            }

            foreach ($snapshot as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();
        }
    }
}
