<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class DocumentSnapshotVerifier
{
    public function verifyInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing(['family', 'correctedInvoice']);
        if (! $invoice->family || $invoice->version < 1) {
            $this->invalid('Invoice family/version metadata is missing.');
        }
        if ($invoice->correctedInvoice) {
            if ($invoice->correctedInvoice->organization_id !== $invoice->organization_id
                || $invoice->correctedInvoice->invoice_family_id !== $invoice->invoice_family_id
                || $invoice->version !== $invoice->correctedInvoice->version + 1) {
                $this->invalid('Invoice correction does not continue the same tenant-scoped family in sequence.');
            }
        } elseif ($invoice->version !== 1) {
            $this->invalid('The first Invoice in a family must be Version 1.');
        }

        // A post-issue correction Draft/Invoice may intentionally differ from the
        // authoritative Sales Order snapshot (that is the whole point of a
        // financial line correction). It is verified for internal consistency
        // instead — never against the Order lines.
        if ($invoice->corrected_invoice_id !== null
            && $invoice->sales_order_revision_id === null
            && $invoice->sales_order_addendum_id === null) {
            $this->verifyCorrectionInvoice($invoice);

            return;
        }

        $invoice->loadMissing(['lines', 'salesOrder.lines.addendum', 'salesOrderAddendum']);
        $boundary = $invoice->salesOrderAddendum;
        if ($boundary && ($boundary->organization_id !== $invoice->organization_id
            || $boundary->sales_order_id !== $invoice->sales_order_id)) {
            $this->invalid('Invoice addendum context is outside its tenant-scoped Sales Order.');
        }

        // An issued document is verified against the commercial boundary it
        // snapshotted, not against additions made to the live Order later.
        $sourceLines = $invoice->salesOrder->lines
            ->filter(fn ($line) => $line->sales_order_addendum_id === null
                || ($boundary && $line->addendum && $line->addendum->sequence <= $boundary->sequence))
            ->keyBy('id');
        if ($invoice->lines->count() !== $sourceLines->count()) {
            $this->invalid('Invoice lines no longer represent the complete Sales Order snapshot.');
        }

        $totals = ['subtotal_excl_tax' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total_incl_tax' => '0.0000'];
        foreach ($invoice->lines as $line) {
            $source = $sourceLines->get($line->sales_order_line_id);
            if (! $source || $line->line_type !== $source->line_type || $line->product_variant_id !== $source->product_variant_id
                || $line->position !== $source->position || $line->description !== $source->product_name) {
                $this->invalid('An Invoice line is not linked to its authoritative Sales Order line.');
            }
            foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'tax_name'] as $field) {
                if ($line->{$field} !== $source->{$field}) {
                    $this->invalid("Invoice line {$field} differs from the Sales Order snapshot.");
                }
            }
            foreach (['quantity', 'unit_price_excl_tax', 'unit_price_incl_tax', 'discount_value', 'subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_rate', 'tax_amount', 'total_incl_tax'] as $field) {
                if (Decimal::compare($line->{$field}, $source->{$field}) !== 0) {
                    $this->invalid("Invoice line {$field} differs from the Sales Order snapshot.");
                }
            }
            if ($line->discount_type !== $source->discount_type) {
                $this->invalid('Invoice line discount type differs from the Sales Order snapshot.');
            }
            $totals['subtotal_excl_tax'] = Decimal::add($totals['subtotal_excl_tax'], $line->subtotal_excl_tax);
            $totals['discount_total'] = Decimal::add($totals['discount_total'], $line->discount_amount);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $line->tax_amount);
            $totals['total_incl_tax'] = Decimal::add($totals['total_incl_tax'], $line->total_incl_tax);
        }
        foreach ($totals as $field => $value) {
            if (Decimal::compare($invoice->{$field}, $value) !== 0) {
                $this->invalid("Invoice {$field} does not reconcile with its immutable line snapshots.");
            }
        }
        if ($invoice->currency_code !== $invoice->salesOrder->currency_code) {
            $this->invalid('Invoice currency differs from the Sales Order snapshot.');
        }
    }

    /**
     * Correction-aware verification. Enforces arithmetic + exact-decimal
     * consistency, tax sanity, the required document snapshots, the correction
     * relationship and the original Invoice's existence — but NOT equality with
     * the Sales Order line snapshot, which a correction may legitimately change.
     */
    public function verifyCorrectionInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing(['lines', 'correctedInvoice']);

        $original = $invoice->correctedInvoice;
        if (! $original) {
            $this->invalid('A correction Invoice must reference the original Invoice it supersedes.');
        }
        if (! in_array($original->status, [InvoiceStatus::Issued, InvoiceStatus::Superseded], true)) {
            $this->invalid('The original Invoice being corrected is not in a correctable state.');
        }
        if ($invoice->lines->isEmpty()) {
            $this->invalid('A correction Invoice must keep at least one line.');
        }
        if ($invoice->currency_code !== $original->currency_code) {
            $this->invalid('Correction currency differs from the original Invoice.');
        }

        $totals = ['subtotal_excl_tax' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'total_incl_tax' => '0.0000'];
        foreach ($invoice->lines as $line) {
            if (Decimal::compare($line->quantity, '0') <= 0) {
                $this->invalid('A correction line quantity must be greater than zero.');
            }
            if (Decimal::compare($line->unit_price_excl_tax, '0') < 0) {
                $this->invalid('A correction line unit price may not be negative.');
            }
            if (Decimal::compare($line->tax_rate, '0') < 0 || Decimal::compare($line->tax_rate, '100') > 0) {
                $this->invalid('A correction line tax rate is out of range.');
            }
            if ($line->line_type === null || $line->description === null || $line->description === '') {
                $this->invalid('A correction line is missing its required document snapshot.');
            }

            $subtotal = Decimal::multiply($line->quantity, $line->unit_price_excl_tax);
            if (Decimal::compare($subtotal, $line->subtotal_excl_tax) !== 0) {
                $this->invalid('A correction line subtotal does not match quantity × unit price HT.');
            }
            if (Decimal::compare($line->discount_amount, '0') < 0 || Decimal::compare($line->discount_amount, $subtotal) > 0) {
                $this->invalid('A correction line discount is outside its valid range.');
            }
            $taxable = Decimal::subtract($subtotal, $line->discount_amount);
            if (Decimal::compare($taxable, $line->taxable_amount) !== 0) {
                $this->invalid('A correction line taxable base is inconsistent.');
            }
            $tax = Decimal::compare($line->tax_rate, '0') === 0 ? '0.0000' : Decimal::percentage($taxable, $line->tax_rate);
            if (Decimal::compare($tax, $line->tax_amount) !== 0) {
                $this->invalid('A correction line tax amount is inconsistent with its taxable base and rate.');
            }
            if (Decimal::compare(Decimal::add($taxable, $tax), $line->total_incl_tax) !== 0) {
                $this->invalid('A correction line total TTC is inconsistent.');
            }

            $totals['subtotal_excl_tax'] = Decimal::add($totals['subtotal_excl_tax'], $line->subtotal_excl_tax);
            $totals['discount_total'] = Decimal::add($totals['discount_total'], $line->discount_amount);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $line->tax_amount);
            $totals['total_incl_tax'] = Decimal::add($totals['total_incl_tax'], $line->total_incl_tax);
        }

        foreach ($totals as $field => $value) {
            if (Decimal::compare($invoice->{$field}, $value) !== 0) {
                $this->invalid("Correction Invoice {$field} does not reconcile with its own line snapshots.");
            }
        }
    }

    public function verifyDeliveryNote(DeliveryNote $note): void
    {
        $note->loadMissing(['lines', 'salesOrder.lines']);
        $sourceLines = $note->salesOrder->lines->keyBy('id');
        if ($note->lines->count() !== $sourceLines->count()) {
            $this->invalid('Delivery Note lines no longer represent the complete fulfilled Order.');
        }
        foreach ($note->lines as $line) {
            $source = $sourceLines->get($line->sales_order_line_id);
            if (! $source || $line->line_type !== $source->line_type || $line->product_variant_id !== $source->product_variant_id
                || $line->position !== $source->position || $line->description !== $source->product_name
                || Decimal::compare($line->quantity, $source->quantity) !== 0) {
                $this->invalid('A Delivery Note line exceeds or differs from the fulfilled Sales Order snapshot.');
            }
            foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label'] as $field) {
                if ($line->{$field} !== $source->{$field}) {
                    $this->invalid("Delivery Note line {$field} differs from the fulfilled Sales Order snapshot.");
                }
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
