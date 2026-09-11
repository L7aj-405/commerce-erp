<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\MutatesInvoiceCorrection;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Remove one line from a correction Draft, then reconcile the Invoice totals.
 * A correction Draft must always keep at least one line — the last line cannot
 * be removed.
 */
class RemoveInvoiceCorrectionLineAction
{
    use MutatesInvoiceCorrection;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Invoice $invoice, InvoiceLine $line): void
    {
        DB::transaction(function () use ($actor, $invoice, $line) {
            $invoice = $this->lockCorrectionDraft($actor, $invoice);
            $line = $this->lockCorrectionLine($invoice, $line);

            if ($invoice->lines->count() <= 1) {
                throw ValidationException::withMessages([
                    'line' => 'Une facture de correction doit conserver au moins une ligne.',
                ]);
            }

            $removed = [
                'invoice_line_id' => $line->getKey(),
                'product_variant_id' => $line->product_variant_id,
                'product_name' => $line->product_name,
                'quantity' => $line->quantity,
                'total_incl_tax' => $line->total_incl_tax,
            ];

            $line->delete();

            $this->reconcileCorrectionTotals($invoice);

            $this->audit->record('invoice.correction_line_removed', $actor, $invoice->organization, $invoice->store, $invoice, oldValues: $removed, newValues: [
                'correction_invoice_id' => $invoice->getKey(),
                'invoice_total_incl_tax' => $invoice->total_incl_tax,
            ]);
        });
    }
}
