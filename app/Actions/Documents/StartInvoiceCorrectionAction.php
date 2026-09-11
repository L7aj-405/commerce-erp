<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentSnapshotVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Open a correction for an already-issued Invoice.
 *
 * The issued Invoice is never mutated. A new draft Invoice is created from its
 * immutable snapshots (customer identity, representative, payment-method wording,
 * date, seller profile, and every InvoiceLine — quantities / HT / discounts /
 * taxes / totals basis) and linked back to the original via
 * `corrected_invoice_id`. Current Product data is not consulted. The correction
 * is then editable through the normal draft flow and issued through
 * IssueInvoiceAction, which supersedes the original at that point.
 */
class StartInvoiceCorrectionAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentSnapshotVerifier $verifier,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Invoice $original, string $reason): Invoice
    {
        $this->authorizeInvoice($actor, $original, 'invoices.issue');

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Un motif de correction est requis.']);
        }

        return DB::transaction(function () use ($actor, $original, $reason) {
            $original = Invoice::query()
                ->where('organization_id', $original->organization_id)
                ->where('store_id', $original->store_id)
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->with(['lines', 'salesOrder', 'organization', 'store'])
                ->firstOrFail();

            if ($original->status !== InvoiceStatus::Issued) {
                throw ValidationException::withMessages(['invoice' => 'Seule une facture émise peut être corrigée.']);
            }

            $activeCorrectionExists = Invoice::query()
                ->where('organization_id', $original->organization_id)
                ->where('corrected_invoice_id', $original->getKey())
                ->whereIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Issued->value])
                ->exists();
            if ($activeCorrectionExists) {
                throw ValidationException::withMessages(['invoice' => 'Une correction est déjà en cours ou émise pour cette facture.']);
            }

            $correction = $original->replicate([
                'invoice_number', 'status', 'issued_at', 'issued_by_user_id',
                'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
            ]);
            $correction->invoice_number = null;
            $correction->status = InvoiceStatus::Draft;
            $correction->issued_at = null;
            $correction->issued_by_user_id = null;
            $correction->cancelled_at = null;
            $correction->cancelled_by_user_id = null;
            $correction->cancellation_reason = null;
            $correction->corrected_invoice_id = $original->getKey();
            $correction->correction_reason = $reason;
            $correction->save();

            foreach ($original->lines as $line) {
                $copy = $line->replicate(['invoice_id']);
                $copy->invoice_id = $correction->getKey();
                $copy->save();
            }

            // The copied lines still reconcile against the authoritative Sales
            // Order snapshot — a correction never rebuilds them from live data.
            $this->verifier->verifyInvoice($correction);

            $this->audit->record('invoice.correction_started', $actor, $original->organization, $original->store, $correction, newValues: [
                'original_invoice_id' => $original->getKey(),
                'original_invoice_number' => $original->invoice_number,
                'correction_invoice_id' => $correction->getKey(),
                'sales_order_number' => $original->salesOrder->order_number,
                'reason' => $reason,
            ]);

            return $correction->load(['lines', 'salesOrder', 'correctedInvoice']);
        });
    }
}
