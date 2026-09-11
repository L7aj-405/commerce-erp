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
 * Append a new catalogue product line to a correction Draft.
 *
 * The line is a fresh document snapshot (identity, reference, designation,
 * presentation, tax rate, unit-price basis) taken from the chosen
 * ProductVariant — never a live reference. It carries no `sales_order_line_id`
 * because it deliberately does not exist on the authoritative Sales Order.
 */
class AddInvoiceCorrectionLineAction
{
    use MutatesInvoiceCorrection;

    public function __construct(
        private readonly SalesLineCalculator $calculator,
        private readonly ProductPriceResolver $priceResolver,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{product_variant_id: int, quantity: string, unit_price_excl_tax?: string|null, discount_type: string, discount_value?: string|null} $data */
    public function execute(User $actor, Invoice $invoice, array $data): InvoiceLine
    {
        return DB::transaction(function () use ($actor, $invoice, $data) {
            $invoice = $this->lockCorrectionDraft($actor, $invoice);

            $resolved = $this->resolveVariantSnapshot($invoice, (int) $data['product_variant_id'], $this->priceResolver);
            $discountType = SalesOrderDiscountType::from($data['discount_type']);
            $unitPrice = $data['unit_price_excl_tax'] ?? null;
            $unitPrice = $unitPrice === null || $unitPrice === ''
                ? $resolved['default_unit_price_excl_tax']
                : Decimal::normalize($unitPrice);

            $calculated = $this->calculator->calculate(
                $data['quantity'],
                $unitPrice,
                $resolved['tax_rate'],
                $discountType,
                $data['discount_value'] ?? '0',
            );

            $line = new InvoiceLine;
            $line->organization_id = $invoice->organization_id;
            $line->invoice_id = $invoice->getKey();
            $line->position = ((int) $invoice->lines()->max('position')) + 1;
            foreach ($resolved['snapshot'] as $field => $value) {
                $line->{$field} = $value;
            }
            $line->discount_type = $discountType;
            foreach ($calculated as $field => $value) {
                $line->{$field} = $value;
            }
            $line->save();

            $this->reconcileCorrectionTotals($invoice);

            $this->audit->record('invoice.correction_line_added', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
                'correction_invoice_id' => $invoice->getKey(),
                'invoice_line_id' => $line->getKey(),
                'product_variant_id' => $line->product_variant_id,
                'product_name' => $line->product_name,
                'quantity' => $calculated['quantity'],
                'unit_price_excl_tax' => $calculated['unit_price_excl_tax'],
                'discount_type' => $discountType->value,
                'discount_value' => $calculated['discount_value'],
                'tax_rate' => $calculated['tax_rate'],
                'total_incl_tax' => $calculated['total_incl_tax'],
                'invoice_total_incl_tax' => $invoice->total_incl_tax,
            ]);

            return $line;
        });
    }
}
