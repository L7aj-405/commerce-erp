<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Invoice;
use App\Models\InvoiceFamily;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentSnapshotVerifier;
use App\Services\DocumentTemplateRegistry;
use App\Services\InvoiceNumberGenerator;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\Decimal;
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
        private readonly SalesOrderPaymentCalculator $payments,
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
            if ($invoice->sales_order_addendum_id !== null) {
                $latestAddendumId = $order->addenda()->orderByDesc('sequence')->value('id');
                if ((int) $latestAddendumId !== (int) $invoice->sales_order_addendum_id) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Ce brouillon ne représente plus le dernier complément de commande.',
                    ]);
                }
            }
            // A correction legitimately co-exists with the issued Invoice it
            // replaces until the moment it is issued — exclude that one original
            // from the "already invoiced" guard.
            if (Invoice::query()->where('organization_id', $invoice->organization_id)->where('sales_order_id', $invoice->sales_order_id)
                ->where('status', InvoiceStatus::Issued->value)->where('id', '!=', $invoice->getKey())
                ->when($invoice->corrected_invoice_id, fn ($query, $id) => $query->where('id', '!=', $id))
                ->exists()) {
                throw ValidationException::withMessages(['order' => 'This Sales Order already has an issued full Invoice.']);
            }
            $this->lockPaymentState($order);
            $remaining = $this->payments->remainingAmount($order);
            if (Decimal::compare($remaining, '0.0000') !== 0) {
                throw ValidationException::withMessages([
                    'payment' => 'La facture ne peut être émise qu’après le règlement intégral de la commande.',
                ]);
            }
            $this->verifier->verifyInvoice($invoice);
            $this->sellerProfile->validate($invoice->seller_snapshot);
            $this->templates->invoiceView($invoice->template_version);
            $family = InvoiceFamily::query()
                ->where('organization_id', $invoice->organization_id)
                ->whereKey($invoice->invoice_family_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($invoice->version === 1) {
                if ($family->canonical_invoice_number === null) {
                    $family->canonical_invoice_number = $this->numbers->next($invoice->organization, (int) $invoice->invoice_date->format('Y'));
                    $family->save();
                }
            } elseif ($family->canonical_invoice_number === null) {
                throw ValidationException::withMessages(['invoice' => 'La famille de facture ne possède pas de numéro canonique.']);
            }
            $invoice->invoice_number = $family->canonical_invoice_number;
            $invoice->status = InvoiceStatus::Issued;
            $invoice->issued_at = now();
            $invoice->issued_by_user_id = $actor->getKey();
            $invoice->save();
            $this->audit->record('invoice.issued', $actor, $invoice->organization, $invoice->store, $invoice, oldValues: ['status' => InvoiceStatus::Draft->value], newValues: [
                'invoice_number' => $invoice->invoice_number, 'sales_order_number' => $invoice->salesOrder->order_number,
                'invoice_version' => $invoice->version,
                'invoice_date' => $invoice->invoice_date->toDateString(), 'status' => InvoiceStatus::Issued->value,
                'total_incl_tax' => $invoice->total_incl_tax,
            ]);

            // Issuing a correction supersedes the Invoice it replaces, in the
            // same transaction. The original stays immutable and viewable.
            if ($invoice->corrected_invoice_id) {
                $original = Invoice::query()->where('organization_id', $invoice->organization_id)
                    ->whereKey($invoice->corrected_invoice_id)->lockForUpdate()->first();
                if ($original && $original->status === InvoiceStatus::Issued) {
                    $original->status = InvoiceStatus::Superseded;
                    $original->save();
                }
                $this->audit->record('invoice.correction_issued', $actor, $invoice->organization, $invoice->store, $invoice, newValues: [
                    'correction_invoice_id' => $invoice->getKey(),
                    'correction_invoice_number' => $invoice->invoice_number,
                    'original_invoice_id' => $original?->getKey(),
                    'original_invoice_number' => $original?->invoice_number,
                    'sales_order_number' => $invoice->salesOrder->order_number,
                    'reason' => $invoice->correction_reason,
                ]);
            }

            return $invoice;
        });
    }

    private function lockPaymentState(SalesOrder $order): void
    {
        $paymentIds = PaymentAllocation::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('payment_id')
            ->unique()
            ->values();

        if ($paymentIds->isNotEmpty()) {
            Payment::query()
                ->where('organization_id', $order->organization_id)
                ->whereIn('id', $paymentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }

        PaymentRefund::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }
}
