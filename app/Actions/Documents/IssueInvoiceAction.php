<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentSnapshotVerifier;
use App\Services\DocumentTemplateRegistry;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueInvoiceAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly DocumentSnapshotVerifier $verifier,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Invoice $invoice): Invoice
    {
        $this->authorizeInvoice($actor, $invoice, 'invoices.issue');

        return DB::transaction(function () use ($actor, $invoice) {
            $invoice = Invoice::query()->where('organization_id', $invoice->organization_id)->where('store_id', $invoice->store_id)
                ->whereKey($invoice->getKey())->lockForUpdate()->with(['organization', 'store', 'salesOrder.lines', 'lines'])->firstOrFail();
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw ValidationException::withMessages(['invoice' => 'Only a draft Invoice can be issued.']);
            }
            $order = SalesOrder::query()->where('organization_id', $invoice->organization_id)->whereKey($invoice->sales_order_id)->lockForUpdate()->firstOrFail();
            if ($order->status !== SalesOrderStatus::Confirmed) {
                throw ValidationException::withMessages(['order' => 'The source Sales Order is no longer eligible for invoicing.']);
            }
            if (Invoice::query()->where('organization_id', $invoice->organization_id)->where('sales_order_id', $invoice->sales_order_id)
                ->where('status', InvoiceStatus::Issued->value)->where('id', '!=', $invoice->getKey())->exists()) {
                throw ValidationException::withMessages(['order' => 'This Sales Order already has an issued full Invoice.']);
            }
            $this->verifier->verifyInvoice($invoice);
            $this->sellerProfile->validate($invoice->seller_snapshot);
            $this->templates->invoiceView($invoice->template_version);
            $invoice->invoice_number = $this->numbers->next($invoice->organization);
            $invoice->status = InvoiceStatus::Issued;
            $invoice->issued_at = now();
            $invoice->issued_by_user_id = $actor->getKey();
            $invoice->save();
            $this->audit->record('invoice.issued', $actor, $invoice->organization, $invoice->store, $invoice, oldValues: ['status' => InvoiceStatus::Draft->value], newValues: [
                'invoice_number' => $invoice->invoice_number, 'sales_order_number' => $invoice->salesOrder->order_number,
                'invoice_date' => $invoice->invoice_date->toDateString(), 'status' => InvoiceStatus::Issued->value,
                'total_incl_tax' => $invoice->total_incl_tax,
            ]);

            return $invoice;
        });
    }
}
