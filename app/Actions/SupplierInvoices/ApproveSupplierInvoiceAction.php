<?php

namespace App\Actions\SupplierInvoices;

use App\Actions\SupplierInvoices\Concerns\AuthorizesSupplierInvoiceAction;
use App\Enums\FinanceObligationCategory;
use App\Enums\SupplierInvoiceStatus;
use App\Models\FinanceObligation;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceInstallment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceClosedPeriodGuard;
use App\Services\Finance\FinanceNumberGenerator;
use App\Services\SupplierInvoices\SupplierInvoiceCalculator;
use App\Services\SupplierInvoices\SupplierInvoiceDuplicateGuard;
use App\Services\SupplierInvoices\SupplierInvoiceMatchingService;
use App\Services\SupplierInvoices\SupplierInvoiceObligationScope;
use App\Services\SupplierInvoices\SupplierInvoiceScheduleBuilder;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use App\Services\SupplierInvoices\SupplierInvoiceWarnings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a supplier invoice DRAFT exactly once and generates one finance obligation per installment (the payable).
 *
 * Lock order: invoice -> supplier -> (period share lock, number sequences, inserts). Approval is a compare-and-swap on
 * status draft -> approved, so concurrent approvals produce one set of obligations; the others return the approved
 * invoice unchanged. Writes no stock, payment or account movement.
 */
class ApproveSupplierInvoiceAction
{
    use AuthorizesSupplierInvoiceAction;

    public function __construct(
        private readonly FinanceNumberGenerator $numbers,
        private readonly FinanceClosedPeriodGuard $periods,
        private readonly SupplierInvoiceCalculator $calculator,
        private readonly SupplierInvoiceDuplicateGuard $duplicates,
        private readonly SupplierInvoiceScheduleBuilder $schedules,
        private readonly SupplierInvoiceMatchingService $matching,
        private readonly SupplierInvoiceWarnings $warnings,
        private readonly SupplierInvoiceSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  control_total_ttc, acknowledge_unmatched?, match_note?, acknowledged_warnings?
     * @return array{invoice: SupplierInvoice, approved_now: bool}
     */
    public function execute(User $actor, SupplierInvoice $invoice, array $data): array
    {
        $organization = $invoice->organization;
        $this->assertInvoiceAccess($actor, $organization, 'supplier_invoices.approve');
        $this->assertStoreAccess($actor, $invoice->store, 'canApprove');

        return DB::transaction(fn () => $this->approve($actor, $invoice, $data), 3);
    }

    /** @return array{invoice: SupplierInvoice, approved_now: bool} */
    private function approve(User $actor, SupplierInvoice $invoice, array $data): array
    {
        $organization = $invoice->organization;
        $locked = SupplierInvoice::query()->where('organization_id', $organization->getKey())->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status === SupplierInvoiceStatus::Approved) {
            return ['invoice' => $locked, 'approved_now' => false];
        }
        if ($locked->status === SupplierInvoiceStatus::Void) {
            throw ValidationException::withMessages(['invoice' => 'Une facture annulée ne peut pas être approuvée.']);
        }
        $invoice = $locked->load(['lines', 'installments', 'store']);

        if ($this->settings->requireDistinctApprover()
            && in_array((int) $actor->getKey(), [(int) $invoice->created_by_user_id, (int) $invoice->last_saved_by_user_id], true)) {
            throw ValidationException::withMessages(['invoice' => 'L’approbation doit être faite par une personne différente de celle qui a saisi la facture.']);
        }

        $supplier = Supplier::query()->where('organization_id', $organization->getKey())->whereKey($invoice->supplier_id)->lockForUpdate()->first();
        if (! $supplier || ! $supplier->active) {
            throw ValidationException::withMessages(['invoice' => 'Le fournisseur est inactif : réactivez-le avant d’approuver cette facture.']);
        }
        if ($this->settings->requireSupplierIceOnApproval() && trim((string) $supplier->ice) === '') {
            throw ValidationException::withMessages(['invoice' => 'L’ICE du fournisseur est obligatoire pour approuver une facture.']);
        }

        $total = $this->assertTotals($invoice, $data['control_total_ttc'] ?? null);
        $installments = $this->installments($invoice, $supplier, $total);
        $dueDates = $installments->map(fn ($installment) => $installment->due_date->toDateString())->all();
        $this->periods->assertOpen($organization, [$invoice->invoice_date, ...$dueDates], 'invoice');
        $this->assertDedupe($invoice);

        $match = $this->matching->match($invoice);
        $note = trim((string) ($data['match_note'] ?? ''));
        if ($match['status'] !== 'po_matched') {
            if (! filter_var($data['acknowledge_unmatched'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['acknowledge_unmatched' => 'La facture n’est pas entièrement rapprochée d’un bon de commande : confirmez-le explicitement pour approuver.']);
            }
            if ($note === '') {
                throw ValidationException::withMessages(['match_note' => 'Une note est obligatoire pour approuver une facture non rapprochée.']);
            }
        }

        $codes = array_values(array_unique(array_column($this->warnings->forInvoice($invoice), 'code')));
        $acknowledged = array_values(array_map('strval', (array) ($data['acknowledged_warnings'] ?? [])));
        $missing = array_values(array_diff($codes, $acknowledged));
        if ($missing !== []) {
            throw ValidationException::withMessages(['acknowledged_warnings' => 'Confirmez les avertissements suivants avant d’approuver : '.implode(', ', $missing).'.']);
        }

        $affected = DB::table('supplier_invoices')
            ->where('id', $invoice->getKey())->where('organization_id', $organization->getKey())->where('status', SupplierInvoiceStatus::Draft->value)
            ->update([
                'status' => SupplierInvoiceStatus::Approved->value,
                'approved_at' => now(),
                'approved_by_user_id' => $actor->getKey(),
                'supplier_name_snapshot' => $supplier->name,
                'supplier_ice_snapshot' => $supplier->ice,
                'supplier_tax_identifier_snapshot' => $supplier->tax_identifier,
                'supplier_rc_snapshot' => $supplier->rc_number,
                'match_status_at_approval' => $match['status'],
                'match_note' => $note === '' ? null : $note,
                'row_version' => (int) $invoice->row_version + 1,
                'updated_at' => now(),
            ]);
        if ($affected !== 1) {
            throw ValidationException::withMessages(['invoice' => 'La facture n’est plus un brouillon : rechargez la page.']);
        }

        $obligations = $this->createObligations($actor, $invoice, $supplier, $installments);

        $this->audit->record('supplier_invoice.approved', $actor, $organization, $invoice->store, $invoice, ['status' => 'draft'], [
            'status' => 'approved',
            'internal_number' => $invoice->internal_number,
            'invoice_number' => $invoice->invoice_number,
            'subtotal_ht' => (string) $invoice->subtotal_ht,
            'total_tva' => (string) $invoice->total_tva,
            'rounding_adjustment' => (string) $invoice->rounding_adjustment,
            'total_ttc' => $total,
            'currency_code' => $invoice->currency_code,
            'obligation_numbers' => array_map(fn (FinanceObligation $obligation) => $obligation->obligation_number, $obligations),
            'match_status' => $match['status'],
            'match_note' => $note === '' ? null : $note,
            'acknowledged_warnings' => $codes,
        ]);

        return ['invoice' => SupplierInvoice::query()->where('organization_id', $organization->getKey())->whereKey($invoice->getKey())->firstOrFail(), 'approved_now' => true];
    }

    /** Stored totals must equal a fresh computation and the paper total typed by the approver. */
    private function assertTotals(SupplierInvoice $invoice, mixed $control): string
    {
        if ($invoice->lines->isEmpty()) {
            throw ValidationException::withMessages(['invoice' => 'La facture ne contient aucune ligne.']);
        }
        $computed = $this->calculator->calculate(
            $invoice->lines->map(fn ($line) => ['quantity' => (string) $line->quantity, 'unit_price_ht' => (string) $line->unit_price_ht, 'tax_rate_percent' => (string) $line->tax_rate_percent])->all(),
            (string) $invoice->rounding_adjustment,
            $this->settings->roundingTolerance(),
        );
        $stale = $computed['total_ttc'] !== (string) $invoice->total_ttc
            || $computed['subtotal_ht'] !== (string) $invoice->subtotal_ht
            || $computed['total_tva'] !== (string) $invoice->total_tva;
        foreach ($invoice->lines as $index => $line) {
            $stale = $stale || $computed['lines'][$index]['line_ttc'] !== (string) $line->line_ttc;
        }
        if ($stale) {
            throw ValidationException::withMessages(['invoice' => 'Les montants enregistrés ne correspondent plus au calcul : enregistrez à nouveau le brouillon.']);
        }
        $this->calculator->assertControlTotal($control === null ? null : (string) $control, $computed['total_ttc']);

        return $computed['total_ttc'];
    }

    /**
     * The validated schedule; a default one (created while still a draft) when none exists and the supplier term is known.
     *
     * @return Collection<int, SupplierInvoiceInstallment>
     */
    private function installments(SupplierInvoice $invoice, Supplier $supplier, string $total)
    {
        $date = $invoice->invoice_date->toDateString();
        if ($invoice->installments->isEmpty()) {
            $default = $this->schedules->defaultSchedule($date, $total, $supplier->default_payment_term_days === null ? null : (int) $supplier->default_payment_term_days);
            foreach ($default as $row) {
                $installment = new SupplierInvoiceInstallment;
                $installment->organization_id = $invoice->organization_id;
                $installment->supplier_invoice_id = $invoice->getKey();
                $installment->position = $row['position'];
                $installment->due_date = $row['due_date'];
                $installment->amount = $row['amount'];
                $installment->save();
            }
            $invoice->load('installments');
        }
        $this->schedules->validate($date, $total, $invoice->installments->map(fn ($installment) => [
            'due_date' => $installment->due_date->toDateString(), 'amount' => (string) $installment->amount,
        ])->all());

        return $invoice->installments->sortBy([fn ($a, $b) => $a->due_date <=> $b->due_date, fn ($a, $b) => $a->position <=> $b->position])->values();
    }

    private function assertDedupe(SupplierInvoice $invoice): void
    {
        $key = $this->duplicates->key($invoice->invoice_number, $invoice->invoice_date);
        $clash = SupplierInvoice::query()->where('organization_id', $invoice->organization_id)->where('supplier_id', $invoice->supplier_id)
            ->where('dedupe_key', $key)->where('id', '!=', $invoice->getKey())->exists();
        if ($invoice->dedupe_key !== $key || $clash) {
            throw ValidationException::withMessages(['invoice' => 'Une facture avec ce numéro existe déjà pour ce fournisseur cette année.']);
        }
    }

    /**
     * @param  Collection<int, SupplierInvoiceInstallment>  $installments
     * @return list<FinanceObligation>
     */
    private function createObligations(User $actor, SupplierInvoice $invoice, Supplier $supplier, $installments): array
    {
        $count = $installments->count();
        $created = [];
        SupplierInvoiceObligationScope::run(function () use ($actor, $invoice, $supplier, $installments, $count, &$created): void {
            foreach ($installments->values() as $index => $installment) {
                $obligation = new FinanceObligation;
                $obligation->organization_id = $invoice->organization_id;
                $obligation->store_id = $invoice->store_id;
                $obligation->obligation_number = $this->numbers->nextObligation($invoice->organization, (int) $installment->due_date->format('Y'));
                $obligation->category = FinanceObligationCategory::Supplier;
                $obligation->label = 'Facture '.$supplier->name.' '.$invoice->invoice_number.' '.($index + 1).'/'.$count;
                $obligation->supplier_id = $supplier->getKey();
                $obligation->reference = $invoice->invoice_number;
                $obligation->due_date = $installment->due_date->toDateString();
                $obligation->planned_amount = (string) $installment->amount;
                $obligation->currency_code = $invoice->currency_code;
                $obligation->planned_payment_method = $installment->planned_payment_method;
                $obligation->preferred_financial_account_id = $installment->preferred_financial_account_id;
                $obligation->notes = $invoice->notes;
                $obligation->created_by_user_id = $actor->getKey();
                $obligation->supplier_invoice_installment_id = $installment->getKey();
                $obligation->save();
                $this->audit->record('finance_obligation.created', $actor, $invoice->organization, $invoice->store, $obligation, newValues: $obligation->only([
                    'obligation_number', 'category', 'label', 'store_id', 'supplier_id', 'beneficiary_name', 'reference', 'due_date', 'planned_amount', 'currency_code', 'planned_payment_method', 'preferred_financial_account_id',
                ]) + ['origin' => 'supplier_invoice', 'supplier_invoice_id' => $invoice->getKey(), 'supplier_invoice_installment_id' => $installment->getKey()]);
                $created[] = $obligation;
            }
        });

        $linked = FinanceObligation::query()->where('organization_id', $invoice->organization_id)
            ->whereIn('supplier_invoice_installment_id', $installments->pluck('id'))->count();
        if ($linked !== $count) {
            throw new \LogicException("Supplier invoice {$invoice->getKey()}: {$linked} obligations generated for {$count} installments.");
        }

        return $created;
    }
}
