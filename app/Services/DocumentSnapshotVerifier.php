<?php

namespace App\Services;

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class DocumentSnapshotVerifier
{
    public function verifyInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing(['lines', 'salesOrder.lines']);
        $sourceLines = $invoice->salesOrder->lines->keyBy('id');
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
            foreach (['quantity', 'unit_price_excl_tax', 'discount_value', 'subtotal_excl_tax', 'discount_amount', 'taxable_amount', 'tax_rate', 'tax_amount', 'total_incl_tax'] as $field) {
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
            if (Decimal::compare($invoice->{$field}, $value) !== 0 || Decimal::compare($invoice->{$field}, $invoice->salesOrder->{$field}) !== 0) {
                $this->invalid("Invoice {$field} does not reconcile with its immutable line snapshots.");
            }
        }
        if ($invoice->currency_code !== $invoice->salesOrder->currency_code) {
            $this->invalid('Invoice currency differs from the Sales Order snapshot.');
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
