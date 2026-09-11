<?php

namespace App\Actions\Documents\Concerns;

use App\Enums\CatalogStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderLineType;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductPriceResolver;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Shared machinery for the three financial-line correction actions
 * (add / update / remove). A correction Draft is the ONLY Invoice on which
 * these run — a normal issued Invoice, a superseded original, and a plain
 * Order-sourced Draft are all rejected here.
 *
 * Every caller wraps this in a DB transaction and holds a `lockForUpdate` row
 * lock on the correction Draft, so line mutation + total reconciliation is
 * atomic and two concurrent edits cannot leave the aggregates stale.
 */
trait MutatesInvoiceCorrection
{
    use AuthorizesDocumentAction;

    /**
     * Re-read the correction Draft under a row lock and prove it is genuinely a
     * still-editable correction. Must be called inside the action's transaction.
     */
    private function lockCorrectionDraft(User $actor, Invoice $invoice): Invoice
    {
        $this->authorizeInvoice($actor, $invoice, 'invoices.update_draft');

        $locked = Invoice::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('store_id', $invoice->store_id)
            ->whereKey($invoice->getKey())
            ->lockForUpdate()
            ->with(['lines', 'organization', 'store', 'correctedInvoice'])
            ->firstOrFail();

        if ($locked->status !== InvoiceStatus::Draft || $locked->corrected_invoice_id === null) {
            throw ValidationException::withMessages([
                'invoice' => 'Seules les lignes d’un brouillon de correction peuvent être modifiées.',
            ]);
        }

        return $locked;
    }

    /**
     * Re-read one InvoiceLine, proving it belongs to THIS correction Draft and
     * tenant. Blocks cross-Invoice line references (IDOR): a line from Invoice A
     * can never be reached through Invoice B.
     */
    private function lockCorrectionLine(Invoice $invoice, InvoiceLine $line): InvoiceLine
    {
        return InvoiceLine::query()
            ->where('organization_id', $invoice->organization_id)
            ->where('invoice_id', $invoice->getKey())
            ->whereKey($line->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Snapshot the document fields InvoiceLine needs from a sellable catalogue
     * identity (ProductVariant), plus the default HT unit price and the
     * effective tax rate resolved through the project's normal pricing
     * architecture (never a hardcoded rate).
     *
     * @return array{
     *   snapshot: array<string, mixed>,
     *   default_unit_price_excl_tax: string,
     *   tax_rate: string,
     *   tax_name: string|null
     * }
     */
    private function resolveVariantSnapshot(Invoice $invoice, int $variantId, ProductPriceResolver $priceResolver): array
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()
            ->where('organization_id', $invoice->organization_id)
            ->whereKey($variantId)
            ->with(['product.defaultUnit', 'taxRate'])
            ->firstOrFail();

        if ($variant->status !== CatalogStatus::Active || $variant->product->status !== CatalogStatus::Active) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Seuls les produits actifs du catalogue peuvent être ajoutés.',
            ]);
        }

        $price = $priceResolver->resolve($variant, $invoice->store);
        if ($price['config_missing']) {
            throw ValidationException::withMessages([
                'product_variant_id' => 'Impossible de calculer le prix HT : aucune taxe par défaut n’est configurée pour ce magasin.',
            ]);
        }

        return [
            'snapshot' => [
                'product_variant_id' => $variant->getKey(),
                'sales_order_line_id' => null,
                'line_type' => SalesOrderLineType::Catalog,
                'description' => $variant->product->name,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->label,
                'sku' => $variant->sku,
                'reference' => $variant->reference,
                'unit_label' => $variant->product->defaultUnit?->symbol ?? $variant->product->defaultUnit?->name,
                'tax_name' => $price['tax_name'],
            ],
            'default_unit_price_excl_tax' => $price['unit_price_ht'],
            'tax_rate' => $price['tax_rate_value'],
            'tax_name' => $price['tax_name'],
        ];
    }

    /**
     * Recompute the correction Draft's four aggregates strictly from its
     * persisted lines. Never trusts a browser-supplied total.
     */
    private function reconcileCorrectionTotals(Invoice $invoice): void
    {
        $invoice->load('lines');

        $subtotal = '0.0000';
        $discount = '0.0000';
        $tax = '0.0000';
        $total = '0.0000';
        foreach ($invoice->lines as $line) {
            $subtotal = Decimal::add($subtotal, $line->subtotal_excl_tax);
            $discount = Decimal::add($discount, $line->discount_amount);
            $tax = Decimal::add($tax, $line->tax_amount);
            $total = Decimal::add($total, $line->total_incl_tax);
        }

        $invoice->subtotal_excl_tax = $subtotal;
        $invoice->discount_total = $discount;
        $invoice->tax_total = $tax;
        $invoice->total_incl_tax = $total;
        $invoice->save();
    }
}
