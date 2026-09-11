<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\MutatesInvoiceCorrection;
use App\Enums\SalesOrderDiscountType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProductPriceResolver;
use App\Services\SalesLineCalculator;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Change quantity / unit price (HT) / discount on one correction-Draft line,
 * and — optionally — replace its Product entirely.
 *
 *  - Same Product: the snapshotted identity AND tax rate are preserved; only
 *    qty / price / discount drive the recalculation.
 *  - Product replaced: reference, designation, presentation, SKU, tax config and
 *    the suggested unit price are all refreshed from the new ProductVariant, the
 *    stale `sales_order_line_id` link is dropped, and the tax rate is re-resolved
 *    through the normal pricing architecture.
 *
 * Every line total is recomputed server-side with the project's exact-decimal
 * SalesLineCalculator — the browser's line/invoice totals are never trusted.
 */
class UpdateInvoiceCorrectionLineAction
{
    use MutatesInvoiceCorrection;

    public function __construct(
        private readonly SalesLineCalculator $calculator,
        private readonly ProductPriceResolver $priceResolver,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{quantity: string, unit_price_excl_tax?: string|null, discount_type: string, discount_value?: string|null, product_variant_id?: int|null} $data */
    public function execute(User $actor, Invoice $invoice, InvoiceLine $line, array $data): InvoiceLine
    {
        return DB::transaction(function () use ($actor, $invoice, $line, $data) {
            $invoice = $this->lockCorrectionDraft($actor, $invoice);
            $line = $this->lockCorrectionLine($invoice, $line);

            $before = $line->only([
                'product_variant_id', 'product_name', 'quantity', 'unit_price_excl_tax',
                'discount_type', 'discount_value', 'tax_rate', 'total_incl_tax',
            ]);

            $discountType = SalesOrderDiscountType::from($data['discount_type']);
            $newVariantId = isset($data['product_variant_id']) && $data['product_variant_id'] !== null
                ? (int) $data['product_variant_id']
                : null;
            $productReplaced = $newVariantId !== null && $newVariantId !== (int) $line->product_variant_id;

            if ($productReplaced) {
                $resolved = $this->resolveVariantSnapshot($invoice, $newVariantId, $this->priceResolver);
                foreach ($resolved['snapshot'] as $field => $value) {
                    $line->{$field} = $value;
                }
                $defaultUnitPrice = $resolved['default_unit_price_excl_tax'];
                $taxRate = $resolved['tax_rate'];
            } else {
                // Same Product: keep the snapshotted tax rate untouched.
                $defaultUnitPrice = Decimal::normalize($line->unit_price_excl_tax);
                $taxRate = Decimal::normalize($line->tax_rate);
            }

            $unitPrice = $data['unit_price_excl_tax'] ?? null;
            $unitPrice = $unitPrice === null || $unitPrice === ''
                ? $defaultUnitPrice
                : Decimal::normalize($unitPrice);

            $calculated = $this->calculator->calculate(
                $data['quantity'],
                $unitPrice,
                $taxRate,
                $discountType,
                $data['discount_value'] ?? '0',
            );

            $line->discount_type = $discountType;
            foreach ($calculated as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();

            $this->reconcileCorrectionTotals($invoice);

            $this->audit->record('invoice.correction_line_updated', $actor, $invoice->organization, $invoice->store, $invoice,
                oldValues: $before,
                newValues: [
                    'correction_invoice_id' => $invoice->getKey(),
                    'invoice_line_id' => $line->getKey(),
                    'product_replaced' => $productReplaced,
                    'product_variant_id' => $line->product_variant_id,
                    'quantity' => $calculated['quantity'],
                    'unit_price_excl_tax' => $calculated['unit_price_excl_tax'],
                    'discount_type' => $discountType->value,
                    'discount_value' => $calculated['discount_value'],
                    'tax_rate' => $calculated['tax_rate'],
                    'total_incl_tax' => $calculated['total_incl_tax'],
                    'invoice_total_incl_tax' => $invoice->total_incl_tax,
                ],
            );

            return $line;
        });
    }
}
