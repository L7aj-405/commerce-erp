<?php

namespace App\Actions\SupplierInvoices;

use App\Actions\SupplierInvoices\Concerns\AuthorizesSupplierInvoiceAction;
use App\Enums\FinanceOutgoingPaymentStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierInvoiceVoidReason;
use App\Models\FinanceObligation;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\Finance\FinanceInstrumentCommitmentService;
use App\Services\SupplierInvoices\SupplierInvoiceObligationScope;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a supplier invoice that was entered by mistake (duplicate, wrong supplier, data-entry error, other).
 *
 * NOT for credit-note situations (supplier avoir, goods return, rebate, adjustment after payment or after a VAT
 * declaration): those belong to the future credit-note feature.
 *
 * A draft just becomes void. An approved invoice is voided only while nothing was paid or committed: its obligations
 * are cancelled inside SupplierInvoiceObligationScope. Nothing is deleted; allocations of reversed payments stay linked.
 * Lock order: invoice -> its obligations (ordered by id, like the payment reversal) -> their payment instruments.
 */
class VoidSupplierInvoiceAction
{
    use AuthorizesSupplierInvoiceAction;

    public function __construct(
        private readonly FinanceInstrumentCommitmentService $commitments,
        private readonly FinanceClosedPeriodGuard $periods,
        private readonly SupplierInvoiceSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  reason_code, reason
     * @return array{invoice: SupplierInvoice, voided_now: bool}
     */
    public function execute(User $actor, SupplierInvoice $invoice, array $data): array
    {
        $organization = $invoice->organization;
        $this->assertInvoiceAccess($actor, $organization, 'supplier_invoices.void');
        $this->assertStoreAccess($actor, $invoice->store, 'canVoid');

        return DB::transaction(fn () => $this->void($actor, $invoice, $data), 3);
    }

    /** @return array{invoice: SupplierInvoice, voided_now: bool} */
    private function void(User $actor, SupplierInvoice $invoice, array $data): array
    {
        $organization = $invoice->organization;
        $invoice = SupplierInvoice::query()->where('organization_id', $organization->getKey())->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
        if ($invoice->status === SupplierInvoiceStatus::Void) {
            return ['invoice' => $invoice, 'voided_now' => false];
        }

        $code = SupplierInvoiceVoidReason::tryFrom((string) ($data['reason_code'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));
        if (! $code) {
            throw ValidationException::withMessages(['reason_code' => 'Le motif d’annulation est invalide.']);
        }
        if ($reason === '' && ($code === SupplierInvoiceVoidReason::Other || $invoice->status === SupplierInvoiceStatus::Approved)) {
            throw ValidationException::withMessages(['reason' => 'Précisez le motif d’annulation.']);
        }

        $wasApproved = $invoice->status === SupplierInvoiceStatus::Approved;
        $obligations = collect();
        if ($invoice->status === SupplierInvoiceStatus::Approved) {
            $obligations = $this->lockAndCheck($invoice);
        }

        $invoice->status = SupplierInvoiceStatus::Void;
        $invoice->voided_at = now();
        $invoice->voided_by_user_id = $actor->getKey();
        $invoice->void_reason_code = $code;
        $invoice->void_reason = $reason === '' ? null : $reason;
        $invoice->dedupe_key = null;
        $invoice->row_version = (int) $invoice->row_version + 1;
        $invoice->save();

        $cancellation = 'Facture fournisseur annulée : '.($reason === '' ? $code->label() : $reason);
        SupplierInvoiceObligationScope::run(function () use ($obligations, $actor, $invoice, $cancellation): void {
            foreach ($obligations as $obligation) {
                $obligation->cancelled_at = now();
                $obligation->cancelled_by_user_id = $actor->getKey();
                $obligation->cancellation_reason = $cancellation;
                $obligation->save();
                $this->audit->record('finance_obligation.cancelled', $actor, $invoice->organization, $invoice->store, $obligation, newValues: [
                    'obligation_number' => $obligation->obligation_number,
                    'reason' => $cancellation,
                ]);
            }
        });

        $this->audit->record('supplier_invoice.voided', $actor, $organization, $invoice->store, $invoice, ['status' => $wasApproved ? 'approved' : 'draft'], [
            'status' => 'void',
            'internal_number' => $invoice->internal_number,
            'invoice_number' => $invoice->invoice_number,
            'total_ttc' => (string) $invoice->total_ttc,
            'currency_code' => $invoice->currency_code,
            'reason_code' => $code->value,
            'reason' => $reason === '' ? null : $reason,
            'obligation_numbers' => $obligations->pluck('obligation_number')->all(),
        ]);

        return ['invoice' => $invoice, 'voided_now' => true];
    }

    /**
     * Locks the invoice's obligations and refuses when anything was paid or committed, or the books are closed.
     *
     * @return Collection<int, FinanceObligation>
     */
    private function lockAndCheck(SupplierInvoice $invoice)
    {
        $installmentIds = $invoice->installments()->pluck('id');
        $obligations = FinanceObligation::query()->where('organization_id', $invoice->organization_id)
            ->whereIn('supplier_invoice_installment_id', $installmentIds)->orderBy('id')->lockForUpdate()->get();

        // Snapshot trap (InnoDB REPEATABLE READ): a locking read of the outer table sees the latest committed rows, but
        // a table read through a subquery (what FinanceObligationCalculator::paid() does with whereHas) is a NON-locking
        // consistent read from this transaction's snapshot. A payment that committed after the snapshot would show its
        // allocation but not its posted status, so paid() could report 0 and the void would cancel a paid obligation.
        // The posted-allocation check below is therefore a locking JOIN read: both tables are read at latest-committed.
        $paid = DB::table('finance_outgoing_payment_allocations as a')
            ->join('finance_outgoing_payments as p', fn ($join) => $join->on('p.id', '=', 'a.outgoing_payment_id')->on('p.organization_id', '=', 'a.organization_id'))
            ->where('a.organization_id', $invoice->organization_id)
            ->whereIn('a.obligation_id', $obligations->pluck('id'))
            ->where('p.status', FinanceOutgoingPaymentStatus::Posted->value)
            ->orderBy('a.id')
            ->lockForUpdate()
            ->first(['a.obligation_id']);
        if ($paid !== null) {
            $number = $obligations->firstWhere('id', $paid->obligation_id)?->obligation_number;
            throw ValidationException::withMessages(['invoice' => "Un paiement est enregistré sur l’échéance {$number} : inversez d’abord les paiements, puis annulez la facture. En cas d’avoir, de retour ou de remise du fournisseur, n’annulez pas la facture (la gestion des avoirs fournisseurs viendra plus tard)."]);
        }
        foreach ($obligations as $obligation) {
            if (Decimal::compare($this->commitments->committed($obligation, null, true), '0.0000') > 0) {
                throw ValidationException::withMessages(['invoice' => "Un chèque ou une traite est en cours sur l’échéance {$obligation->obligation_number} : annulez-le d’abord."]);
            }
        }

        $this->periods->assertOpen($invoice->organization, [$invoice->invoice_date, ...$obligations->map(fn ($obligation) => $obligation->due_date)->all()], 'invoice');

        $limit = $this->settings->voidMaxAgeDays();
        if ($limit !== null && $invoice->invoice_date->lt(Carbon::today()->subDays($limit))) {
            throw ValidationException::withMessages(['invoice' => "La date de cette facture remonte à plus de {$limit} jours : elle ne peut plus être annulée."]);
        }

        return $obligations;
    }
}
