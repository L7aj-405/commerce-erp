<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelInvoiceDraftAction
{
    use AuthorizesDocumentAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Invoice $invoice, ?string $reason = null): Invoice
    {
        $this->authorizeInvoice($actor, $invoice, 'invoices.update_draft');

        return DB::transaction(function () use ($actor, $invoice, $reason) {
            $invoice = Invoice::query()->where('organization_id', $invoice->organization_id)->where('store_id', $invoice->store_id)
                ->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'Only a draft Invoice can be cancelled.']);
            }
            $invoice->status = InvoiceStatus::Cancelled;
            $invoice->cancelled_at = now();
            $invoice->cancelled_by_user_id = $actor->getKey();
            $invoice->cancellation_reason = $reason;
            $invoice->save();
            $this->audit->record('invoice.draft_cancelled', $actor, $invoice->organization, $invoice->store, $invoice, oldValues: ['status' => InvoiceStatus::Draft->value], newValues: [
                'sales_order_number' => $invoice->salesOrder->order_number, 'status' => InvoiceStatus::Cancelled->value,
                'invoice_date' => $invoice->invoice_date->toDateString(), 'reason' => $reason, 'total_incl_tax' => $invoice->total_incl_tax,
            ]);

            return $invoice;
        });
    }
}
