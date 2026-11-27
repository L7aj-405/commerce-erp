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
use App\Services\Finance\CustomerInvoicingPolicyResolver;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\InvoiceNumberGenerator;
use App\Services\Policies\PolicyResolver;
use App\Services\SalesOrderPaymentCalculator;
use App\Services\Sandbox\SandboxDocumentNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueInvoiceAction
{
    use AuthorizesDocumentAction;

    /**
     * U-2: refusal shown when the order is not fully settled. Same validation key
     * ('invoice') and same blocking rule as before; the text now explains the
     * business reason and the next step.
     */
    public const FULL_PAYMENT_REQUIRED_MESSAGE = "La facture ne peut être émise qu'après le règlement intégral de la commande : une facture émise est un document comptable définitif qui constate une vente entièrement encaissée. Enregistrez d'abord le paiement du solde restant sur la commande (bouton « Enregistrer un paiement »), puis émettez la facture.";

    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly DocumentSnapshotVerifier $verifier,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly SandboxDocumentNumberGenerator $testNumbers,
        private readonly FinanceClosedPeriodGuard $periods,
        private readonly PolicyResolver $policies,
        private readonly CustomerInvoicingPolicyResolver $invoicingPolicy,
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
            $test = $invoice->sandbox_id !== null;
            $order = SalesOrder::query()->where('organization_id', $invoice->organization_id)->whereKey($invoice->sales_order_id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, $test ? [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed] : [SalesOrderStatus::Confirmed], true)) {
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
            // Payments are not sandboxed: a Test invoice has no settlement gate
            // and touches no payment row.
            if (! $test) {
                $this->lockPaymentState($order);
            }
            if (! $test && ! $this->invoicingPolicy->allowsIssuance($order, $this->payments->remainingAmount($order))) {
                throw ValidationException::withMessages([
                    'invoice' => self::FULL_PAYMENT_REQUIRED_MESSAGE,
                ]);
            }
            // F-03: an issued invoice is an accounting posting dated invoice_date (it keys the fiscal sequence
            // year and every monthly report). Refused when that month is closed. Issuing a correction also
            // supersedes the original, which removes it from ITS month's figures, so that month must be open
            // too. Taken after the invoice/order/payment locks and before the number-family lock: the guard
            // only takes a SHARED lock on the month row, which closing needs exclusively, so issuing and
            // closing the same month serialize. Test Mode invoices belong to no Production month.
            if (! $test) {
                $this->periods->assertOpen($invoice->organization_id, [
                    $invoice->invoice_date,
                    $invoice->corrected_invoice_id
                        ? Invoice::query()->where('organization_id', $invoice->organization_id)->whereKey($invoice->corrected_invoice_id)->value('invoice_date')
                        : null,
                ], 'invoice_date');
            }
            $this->verifier->verifyInvoice($invoice);
            $invoice->seller_snapshot = $this->sellerProfile->freezeCurrentPresentationSettings(
                $invoice->seller_snapshot,
                $invoice->organization,
            );
            $this->sellerProfile->validate($invoice->seller_snapshot);
            $this->templates->invoiceView($invoice->template_version);
            $family = InvoiceFamily::query()
                ->where('organization_id', $invoice->organization_id)
                ->whereKey($invoice->invoice_family_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($invoice->version === 1) {
                if ($family->canonical_invoice_number === null) {
                    $family->canonical_invoice_number = $test
                        ? $this->testNumbers->next($invoice->organization, SandboxDocumentNumberGenerator::INVOICE)
                        : $this->numbers->next($invoice->organization, (int) $invoice->invoice_date->format('Y'));
                    $family->save();
                }
            } elseif ($family->canonical_invoice_number === null) {
                throw ValidationException::withMessages(['invoice' => 'La famille de facture ne possède pas de numéro canonique.']);
            }
            $invoice->invoice_number = $family->canonical_invoice_number;
            $invoice->status = InvoiceStatus::Issued;
            $invoice->issued_at = now();
            // W4.4 payment term, stamped once: a correction keeps the due date of the invoice it replaces.
            $invoice->due_date ??= ($invoice->corrected_invoice_id
                ? Invoice::query()->where('organization_id', $invoice->organization_id)->whereKey($invoice->corrected_invoice_id)->value('due_date')
                : null) ?? $invoice->invoice_date->copy()->addDays($this->policies->invoicePaymentTermDays($invoice->organization));
            $invoice->issued_by_user_id = $actor->getKey();
            $invoice->save();
            $this->audit->record('invoice.issued', $actor, $invoice->organization, $invoice->store, $invoice, oldValues: ['status' => InvoiceStatus::Draft->value], newValues: [
                'invoice_number' => $invoice->invoice_number, 'sales_order_number' => $invoice->salesOrder->order_number,
                'invoice_version' => $invoice->version,
                'invoice_date' => $invoice->invoice_date->toDateString(), 'due_date' => $invoice->due_date?->toDateString(), 'status' => InvoiceStatus::Issued->value,
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
