<?php

namespace App\Services\Finance;

use App\Enums\InvoiceStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\DocumentSnapshotVerifier;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 1 Step 8 — READ-ONLY financial / stock consistency checks R-1 … R-10
 * (docs/phase-1-foundation/FINANCIAL_RECONCILIATION_AUDIT.md §4).
 *
 * Never writes: every check is a SELECT (or a read-only snapshot verification).
 * Every query is filtered by the given organization and, on tables shared with
 * Test Mode (sales_orders, invoices, credit_notes), by `sandbox_id IS NULL`;
 * Test payments / refunds / stock live in separate sandbox_* tables and are
 * therefore never read here. Aggregates are computed in SQL (no unbounded
 * loads); only document-snapshot verification iterates, in bounded chunks.
 *
 * A check result: code, title, status (pass|fail|skipped), expected, actual,
 * variance, violations (count), samples (<= $sample rows), note.
 */
class FinanceReconciliationService
{
    private const OPEN_ENDED_AS_OF = '2999-12-31';

    public function __construct(
        private readonly FinanceReceivablesService $receivables,
        private readonly FinanceMonthlyReportService $report,
        private readonly FinancialAccountBalanceService $accounts,
        private readonly DocumentSnapshotVerifier $verifier,
    ) {}

    /**
     * @param  list<string>  $months  "YYYY-MM" periods checked by R-3 and R-4
     * @return list<array<string, mixed>>
     */
    public function run(Organization $organization, array $months, int $sample = 20): array
    {
        return [
            $this->r1ReceivablesEqualExposure($organization),
            $this->r2AccountBalances($organization, $sample),
            $this->r3MonthlyCollections($organization, $months),
            $this->r4MonthlyNetInvoiced($organization, $months),
            $this->r5CollectionsAndAllocations($organization, $sample),
            $this->r6DocumentSnapshots($organization, $sample),
            $this->r7StockEqualsMovements($organization, $sample),
            $this->r8SupplierInvoiceTotalsEqualObligations($organization, $sample),
            $this->r9InstallmentsMatchObligations($organization, $sample),
            $this->r10SupplierInvoiceAllocations($organization, $sample),
        ];
    }

    /** R-1: Σ invoice receivables + Σ unbilled exposure = Σ remaining of confirmed orders. */
    public function r1ReceivablesEqualExposure(Organization $organization): array
    {
        $asOf = Carbon::parse(self::OPEN_ENDED_AS_OF);
        $receivable = $this->receivables->totalAsOf($organization, $asOf, null);
        $exposure = $this->receivables->orderExposureSummaryAsOf($organization, $asOf, null)['total'];

        $unbilledQuery = $this->receivables->orderExposureQuery($organization, $asOf, null)
            ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))->from('invoices')
                ->whereColumn('invoices.sales_order_id', 'sales_orders.id')
                ->where('invoices.organization_id', $organization->getKey())
                ->whereNull('invoices.sandbox_id')
                ->whereIn('invoices.status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value]));
        $unbilled = Decimal::normalize((string) DB::query()->fromSub($unbilledQuery->toBase(), 'u')
            ->selectRaw('COALESCE(SUM(outstanding_raw), 0) as total')->value('total'));

        $actual = Decimal::add($receivable, $unbilled);

        return $this->equality('R-1', 'Receivables + unbilled exposure = remaining on confirmed orders', $exposure, $actual,
            note: "receivable={$receivable}; unbilled={$unbilled}");
    }

    /** R-2: each configured account balance = opening + posted payments - refunds - outgoing payments. */
    public function r2AccountBalances(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();
        $today = FinancePeriod::businessToday($organization);
        $position = $this->accounts->organizationPosition($organization, $today);
        $rows = array_filter($position['accounts'], fn (array $a) => $a['configured']);
        if ($rows === []) {
            return $this->skipped('R-2', 'Financial account balances = opening + movements', $this->r2CoverageNote($id, $today->toDateString(), []));
        }

        $violations = [];
        foreach ($rows as $row) {
            $from = $row['effective_date'];
            $sum = fn (string $table, string $dateColumn, string $status) => Decimal::normalize((string) DB::table($table)
                ->where('organization_id', $id)->where('financial_account_id', $row['id'])->where('status', $status)
                ->whereDate($dateColumn, '>=', $from)->whereDate($dateColumn, '<=', $today->toDateString())
                ->selectRaw('COALESCE(ROUND(SUM(amount), 4), 0) as s')->value('s'));
            $expected = Decimal::subtract(Decimal::subtract(Decimal::add($row['opening_amount'], $sum('payments', 'payment_date', 'posted')),
                $sum('payment_refunds', 'refund_date', 'posted')), $sum('finance_outgoing_payments', 'payment_date', 'posted'));
            if (Decimal::compare($expected, $row['balance']) !== 0) {
                $variance = Decimal::subtract($row['balance'], $expected);
                $violations[] = ['account_id' => $row['id'], 'code' => $row['code'], 'expected' => $expected, 'reported' => $row['balance'], 'variance' => $variance, 'classification' => $this->classify($variance)];
            }
        }

        $note = $this->r2CoverageNote($id, $today->toDateString(), array_column($rows, 'id'));

        return $this->violationResult('R-2', 'Financial account balances = opening + movements', $violations, $sample, $note);
    }

    /**
     * Coverage disclosure for R-2 (read-only; aggregate counts and account ids only, never names/codes/amounts).
     * R-2 itself only examines ACTIVE accounts whose opening position is effective on or before today; this states
     * how many accounts that was, which accounts were left out and why, and whether the excluded ones carry posted
     * movements. A PASS therefore only covers the examined accounts.
     *
     * @param  list<int|string>  $checkedIds
     */
    private function r2CoverageNote(int $organizationId, string $today, array $checkedIds): string
    {
        $accounts = DB::table('financial_accounts as fa')
            ->leftJoin('financial_account_opening_positions as op', function ($join) {
                $join->on('op.organization_id', '=', 'fa.organization_id')->on('op.financial_account_id', '=', 'fa.id');
            })
            ->where('fa.organization_id', $organizationId)
            ->orderBy('fa.id')
            ->get(['fa.id', 'fa.status', 'op.effective_date']);

        $total = $accounts->count();
        if ($total === 0) {
            return 'Coverage: 0 of 0 account(s) checked; the organization has no financial account.';
        }

        $checked = array_map('intval', $checkedIds);
        $reasons = ['inactive' => [], 'no_opening_position' => [], 'opening_in_future' => []];
        foreach ($accounts as $account) {
            if (in_array((int) $account->id, $checked, true)) {
                continue;
            }
            $effective = $account->effective_date === null ? null : substr((string) $account->effective_date, 0, 10);
            $key = $account->status !== 'active' ? 'inactive' : ($effective === null ? 'no_opening_position' : 'opening_in_future');
            $reasons[$key][] = (int) $account->id;
        }

        $excludedIds = array_merge(...array_values($reasons));
        $note = count($checked).' of '.$total.' account(s) checked.';
        if ($excludedIds === []) {
            return "Coverage: {$note} All financial accounts were examined.";
        }

        $labels = ['inactive' => 'inactive status', 'no_opening_position' => 'no opening position', 'opening_in_future' => 'opening position effective after '.$today];
        $parts = [];
        foreach ($reasons as $key => $ids) {
            if ($ids !== []) {
                $parts[] = count($ids)." {$labels[$key]}";
            }
        }

        $withMovements = [];
        foreach ([['payments'], ['payment_refunds'], ['finance_outgoing_payments']] as [$table]) {
            foreach (DB::table($table)->where('organization_id', $organizationId)->where('status', 'posted')
                ->whereIn('financial_account_id', $excludedIds)->distinct()->pluck('financial_account_id') as $id) {
                $withMovements[(int) $id] = true;
            }
        }
        ksort($withMovements);
        $movementText = $withMovements === []
            ? 'None of them has posted movements.'
            : count($withMovements).' of them have posted movements that were NOT reconciled (account id '.implode(', ', array_slice(array_keys($withMovements), 0, 20)).').';

        return "Coverage: {$note} ".count($excludedIds).' account(s) not checked ('.implode('; ', $parts).'). '.$movementText
            .' A PASS covers only the checked accounts.';
    }

    /** R-3: monthly collected (report) = Σ posted allocations of the month - refunds of the month. */
    public function r3MonthlyCollections(Organization $organization, array $months): array
    {
        $id = $organization->getKey();
        $violations = [];
        foreach ($months as $month) {
            $period = FinancePeriod::fromMonth($month);
            $reported = Decimal::subtract($this->report->encaissementsTotal($organization, $period, null), $this->report->refundsTotal($organization, $period, null));
            $allocated = Decimal::normalize((string) DB::table('payment_allocations as a')
                ->join('payments as p', fn ($j) => $j->on('p.id', '=', 'a.payment_id')->on('p.organization_id', '=', 'a.organization_id'))
                ->where('a.organization_id', $id)->where('p.status', 'posted')
                ->whereDate('p.payment_date', '>=', $period->start->toDateString())->whereDate('p.payment_date', '<=', $period->end->toDateString())
                ->selectRaw('COALESCE(ROUND(SUM(a.amount), 4), 0) as s')->value('s'));
            $refunded = Decimal::normalize((string) DB::table('payment_refunds')
                ->where('organization_id', $id)->where('status', 'posted')
                ->whereDate('refund_date', '>=', $period->start->toDateString())->whereDate('refund_date', '<=', $period->end->toDateString())
                ->selectRaw('COALESCE(ROUND(SUM(amount), 4), 0) as s')->value('s'));
            $expected = Decimal::subtract($allocated, $refunded);
            if (Decimal::compare($expected, $reported) !== 0) {
                $variance = Decimal::subtract($reported, $expected);
                $violations[] = ['month' => $month, 'expected' => $expected, 'reported' => $reported, 'variance' => $variance, 'classification' => $this->classify($variance)];
            }
        }

        return $this->violationResult('R-3', 'Monthly net collected = Σ allocations - refunds', $violations, 50, 'Months checked: '.count($months));
    }

    /** R-4: monthly net invoiced (report) = Σ issued invoices - Σ issued credit notes. */
    public function r4MonthlyNetInvoiced(Organization $organization, array $months): array
    {
        $id = $organization->getKey();
        $violations = [];
        foreach ($months as $month) {
            $period = FinancePeriod::fromMonth($month);
            $reported = Decimal::subtract($this->report->facturationTotal($organization, $period, null), $this->report->creditNotesTotal($organization, $period, null));
            $between = fn (string $table, string $dateColumn, string $status) => Decimal::normalize((string) DB::table($table)
                ->where('organization_id', $id)->whereNull('sandbox_id')->where('status', $status)
                ->whereDate($dateColumn, '>=', $period->start->toDateString())->whereDate($dateColumn, '<=', $period->end->toDateString())
                ->selectRaw('COALESCE(ROUND(SUM(total_incl_tax), 4), 0) as s')->value('s'));
            $expected = Decimal::subtract($between('invoices', 'invoice_date', 'issued'), $between('credit_notes', 'credit_note_date', 'issued'));
            if (Decimal::compare($expected, $reported) !== 0) {
                $variance = Decimal::subtract($reported, $expected);
                $violations[] = ['month' => $month, 'expected' => $expected, 'reported' => $reported, 'variance' => $variance, 'classification' => $this->classify($variance)];
            }
        }

        return $this->violationResult('R-4', 'Monthly net invoiced = invoices - credit notes', $violations, 50, 'Months checked: '.count($months));
    }

    /** R-5: no order collected-refunded < 0; each posted payment equals the sum of its allocations. */
    public function r5CollectionsAndAllocations(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();

        $collected = DB::table('payment_allocations as a')
            ->join('payments as p', fn ($j) => $j->on('p.id', '=', 'a.payment_id')->on('p.organization_id', '=', 'a.organization_id'))
            ->where('a.organization_id', $id)->where('p.status', 'posted')
            ->groupBy('a.sales_order_id')->select('a.sales_order_id')->selectRaw('SUM(a.amount) as collected');
        $refunded = DB::table('payment_refunds')->where('organization_id', $id)->where('status', 'posted')
            ->groupBy('sales_order_id')->select('sales_order_id')->selectRaw('SUM(amount) as refunded');
        $overRefunded = DB::table('sales_orders as o')
            ->leftJoinSub($collected, 'c', 'c.sales_order_id', '=', 'o.id')
            ->leftJoinSub($refunded, 'r', 'r.sales_order_id', '=', 'o.id')
            ->where('o.organization_id', $id)->whereNull('o.sandbox_id')
            ->whereRaw('ROUND(COALESCE(c.collected, 0) - COALESCE(r.refunded, 0), 4) < 0')
            ->select(['o.id as sales_order_id', 'o.order_number'])
            ->selectRaw('ROUND(COALESCE(c.collected, 0), 4) as collected')->selectRaw('ROUND(COALESCE(r.refunded, 0), 4) as refunded');

        $allocated = DB::table('payment_allocations')->where('organization_id', $id)
            ->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) as allocated');
        $unbalanced = DB::table('payments as p')
            ->leftJoinSub($allocated, 'a', 'a.payment_id', '=', 'p.id')
            ->where('p.organization_id', $id)->where('p.status', 'posted')
            ->whereRaw('ROUND(p.amount - COALESCE(a.allocated, 0), 4) <> 0')
            ->select(['p.id as payment_id', 'p.payment_number'])
            ->selectRaw('ROUND(p.amount, 4) as amount')->selectRaw('ROUND(COALESCE(a.allocated, 0), 4) as allocated');

        $samples = [];
        $count = 0;
        foreach (['order_refunded_more_than_collected' => $overRefunded, 'payment_not_equal_to_allocations' => $unbalanced] as $kind => $query) {
            $count += $this->count($query);
            foreach ((clone $query)->limit($sample)->get() as $row) {
                $samples[] = ['kind' => $kind] + (array) $row;
            }
        }

        return [
            'code' => 'R-5', 'title' => 'Orders: collected - refunded >= 0; payments = Σ allocations',
            'status' => $count === 0 ? 'pass' : 'fail', 'expected' => null, 'actual' => null, 'variance' => null,
            'violations' => $count, 'samples' => array_slice($samples, 0, $sample), 'note' => null, 'classification' => null,
        ];
    }

    /** R-6: no issued fiscal document differs from its issue-time snapshot (DocumentSnapshotVerifier). */
    public function r6DocumentSnapshots(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();
        $checked = 0;
        $violations = [];
        $record = function (string $type, int|string $docId, ?string $number, Throwable $e) use (&$violations) {
            $message = $e instanceof ValidationException ? implode(' ', array_merge(...array_values($e->errors()))) : $e->getMessage();
            $violations[] = ['document' => $type, 'id' => $docId, 'number' => $number, 'error' => $message];
        };

        Invoice::query()->where('organization_id', $id)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Superseded->value])
            ->chunkById(200, function ($invoices) use (&$checked, $record) {
                foreach ($invoices as $invoice) {
                    $checked++;
                    try {
                        $this->verifier->verifyInvoice($invoice);
                    } catch (Throwable $e) {
                        $record('invoice', $invoice->getKey(), $invoice->invoice_number ?? null, $e);
                    }
                }
            });
        DeliveryNote::query()->where('organization_id', $id)->where('status', 'issued')
            ->chunkById(200, function ($notes) use (&$checked, $record) {
                foreach ($notes as $note) {
                    $checked++;
                    try {
                        $this->verifier->verifyDeliveryNote($note);
                    } catch (Throwable $e) {
                        $record('delivery_note', $note->getKey(), $note->delivery_note_number ?? null, $e);
                    }
                }
            });

        return $this->violationResult('R-6', 'Issued documents match their snapshots', $violations, $sample, "Documents verified: {$checked}");
    }

    /** R-7: per warehouse/variant, on-hand balance = Σ movement deltas. */
    public function r7StockEqualsMovements(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();
        $moved = DB::table('inventory_movements')->where('organization_id', $id)
            ->groupBy('warehouse_id', 'product_variant_id')->select('warehouse_id', 'product_variant_id')
            ->selectRaw('SUM(quantity_after - quantity_before) as moved');

        $balanceDrift = DB::table('inventory_balances as b')
            ->leftJoinSub($moved, 'm', fn ($j) => $j->on('m.warehouse_id', '=', 'b.warehouse_id')->on('m.product_variant_id', '=', 'b.product_variant_id'))
            ->where('b.organization_id', $id)
            ->whereRaw('ROUND(b.on_hand - COALESCE(m.moved, 0), 4) <> 0')
            ->select(['b.warehouse_id', 'b.product_variant_id'])
            ->selectRaw('ROUND(b.on_hand, 4) as on_hand')->selectRaw('ROUND(COALESCE(m.moved, 0), 4) as movements');
        $orphanMovements = DB::table('inventory_movements as v')
            ->where('v.organization_id', $id)
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('inventory_balances as b')
                ->whereColumn('b.warehouse_id', 'v.warehouse_id')->whereColumn('b.product_variant_id', 'v.product_variant_id')
                ->where('b.organization_id', $id))
            ->groupBy('v.warehouse_id', 'v.product_variant_id')
            ->havingRaw('ROUND(SUM(v.quantity_after - v.quantity_before), 4) <> 0')
            ->select(['v.warehouse_id', 'v.product_variant_id'])
            ->selectRaw('0 as on_hand')->selectRaw('ROUND(SUM(v.quantity_after - v.quantity_before), 4) as movements');

        $samples = [];
        $count = 0;
        foreach ([$balanceDrift, $orphanMovements] as $query) {
            $count += $this->count($query);
            foreach ((clone $query)->limit($sample)->get() as $row) {
                $samples[] = (array) $row;
            }
        }

        return [
            'code' => 'R-7', 'title' => 'Stock balance = Σ movements per warehouse/variant',
            'status' => $count === 0 ? 'pass' : 'fail', 'expected' => null, 'actual' => null, 'variance' => null,
            'violations' => $count, 'samples' => array_slice($samples, 0, $sample), 'note' => null, 'classification' => null,
        ];
    }

    /**
     * R-8: for every APPROVED supplier invoice, total_ttc = Σ planned amount of its linked non-cancelled
     * obligations (reached through the invoice installments). Legacy manual obligations are never involved.
     */
    public function r8SupplierInvoiceTotalsEqualObligations(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();
        $query = DB::table('supplier_invoices as i')
            ->leftJoin('supplier_invoice_installments as n', fn ($j) => $j->on('n.supplier_invoice_id', '=', 'i.id')->on('n.organization_id', '=', 'i.organization_id'))
            ->leftJoin('finance_obligations as o', fn ($j) => $j->on('o.supplier_invoice_installment_id', '=', 'n.id')->on('o.organization_id', '=', 'n.organization_id')->whereNull('o.cancelled_at'))
            ->where('i.organization_id', $id)->where('i.status', 'approved')
            ->groupBy('i.id', 'i.internal_number', 'i.total_ttc')
            ->havingRaw('ROUND(i.total_ttc - COALESCE(SUM(o.planned_amount), 0), 4) <> 0')
            ->select(['i.id as supplier_invoice_id', 'i.internal_number'])
            ->selectRaw('ROUND(i.total_ttc, 4) as invoice_total')
            ->selectRaw('ROUND(COALESCE(SUM(o.planned_amount), 0), 4) as obligations_total');
        $approved = (int) DB::table('supplier_invoices')->where('organization_id', $id)->where('status', 'approved')->count();

        return $this->queriesResult('R-8', 'Approved supplier invoice total = Σ linked obligations', [$query], $sample, "Approved supplier invoices checked: {$approved}");
    }

    /**
     * R-9: installments <-> obligations are 1:1 for approved supplier invoices (every installment has its
     * obligation, same due date / amount / store / supplier / currency); no live obligation hangs on a draft or
     * void invoice.
     */
    public function r9InstallmentsMatchObligations(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();
        $missing = DB::table('supplier_invoice_installments as n')
            ->join('supplier_invoices as i', fn ($j) => $j->on('i.id', '=', 'n.supplier_invoice_id')->on('i.organization_id', '=', 'n.organization_id'))
            ->where('i.organization_id', $id)->where('i.status', 'approved')
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('finance_obligations as o')
                ->whereColumn('o.supplier_invoice_installment_id', 'n.id')->whereColumn('o.organization_id', 'n.organization_id')->whereNull('o.cancelled_at'))
            ->select(['i.id as supplier_invoice_id', 'n.id as installment_id'])->selectRaw("'installment_without_obligation' as issue");
        $mismatch = DB::table('supplier_invoice_installments as n')
            ->join('supplier_invoices as i', fn ($j) => $j->on('i.id', '=', 'n.supplier_invoice_id')->on('i.organization_id', '=', 'n.organization_id'))
            ->join('finance_obligations as o', fn ($j) => $j->on('o.supplier_invoice_installment_id', '=', 'n.id')->on('o.organization_id', '=', 'n.organization_id'))
            ->where('i.organization_id', $id)->where('i.status', 'approved')->whereNull('o.cancelled_at')
            ->where(fn ($q) => $q->whereRaw('DATE(o.due_date) <> DATE(n.due_date)')
                ->orWhereRaw('ROUND(o.planned_amount - n.amount, 4) <> 0')
                ->orWhereRaw('COALESCE(o.store_id, 0) <> i.store_id')
                ->orWhereRaw('COALESCE(o.supplier_id, 0) <> i.supplier_id')
                ->orWhereRaw('o.currency_code <> i.currency_code'))
            ->select(['i.id as supplier_invoice_id', 'n.id as installment_id'])->selectRaw("'obligation_differs_from_installment' as issue");
        $live = DB::table('supplier_invoice_installments as n')
            ->join('supplier_invoices as i', fn ($j) => $j->on('i.id', '=', 'n.supplier_invoice_id')->on('i.organization_id', '=', 'n.organization_id'))
            ->join('finance_obligations as o', fn ($j) => $j->on('o.supplier_invoice_installment_id', '=', 'n.id')->on('o.organization_id', '=', 'n.organization_id'))
            ->where('i.organization_id', $id)->where('i.status', '<>', 'approved')->whereNull('o.cancelled_at')
            ->select(['i.id as supplier_invoice_id', 'n.id as installment_id'])->selectRaw("'live_obligation_on_non_approved_invoice' as issue");

        return $this->queriesResult('R-9', 'Supplier invoice installments = obligations (1:1)', [$missing, $mismatch, $live], $sample, null);
    }

    /**
     * R-10: for obligations generated from supplier invoices, Σ POSTED outgoing-payment allocations <= planned
     * amount (reversed payments excluded), and a cancelled obligation carries no posted allocation.
     */
    public function r10SupplierInvoiceAllocations(Organization $organization, int $sample): array
    {
        $id = $organization->getKey();
        $posted = fn () => DB::table('finance_obligations as o')
            ->join('finance_outgoing_payment_allocations as a', fn ($j) => $j->on('a.obligation_id', '=', 'o.id')->on('a.organization_id', '=', 'o.organization_id'))
            ->join('finance_outgoing_payments as p', fn ($j) => $j->on('p.id', '=', 'a.outgoing_payment_id')->on('p.organization_id', '=', 'a.organization_id'))
            ->where('o.organization_id', $id)->whereNotNull('o.supplier_invoice_installment_id')->where('p.status', 'posted');
        $over = $posted()->whereNull('o.cancelled_at')->groupBy('o.id', 'o.obligation_number', 'o.planned_amount')
            ->havingRaw('ROUND(SUM(a.amount) - o.planned_amount, 4) > 0')
            ->select(['o.id as obligation_id', 'o.obligation_number'])->selectRaw('ROUND(o.planned_amount, 4) as planned_amount')
            ->selectRaw('ROUND(SUM(a.amount), 4) as allocated')->selectRaw("'over_allocated' as issue");
        $cancelled = $posted()->whereNotNull('o.cancelled_at')->groupBy('o.id', 'o.obligation_number', 'o.planned_amount')
            ->select(['o.id as obligation_id', 'o.obligation_number'])->selectRaw('ROUND(o.planned_amount, 4) as planned_amount')
            ->selectRaw('ROUND(SUM(a.amount), 4) as allocated')->selectRaw("'posted_allocation_on_cancelled_obligation' as issue");

        $linked = (int) DB::table('finance_obligations')->where('organization_id', $id)->whereNotNull('supplier_invoice_installment_id')->count();

        return $this->queriesResult('R-10', 'Supplier invoice obligations: Σ posted allocations <= planned', [$over, $cancelled], $sample, "Supplier invoice obligations checked: {$linked}");
    }

    /** @param list<Builder> $queries Violation queries; the result counts every row and samples at most $sample. */
    private function queriesResult(string $code, string $title, array $queries, int $sample, ?string $note): array
    {
        $count = 0;
        $samples = [];
        foreach ($queries as $query) {
            $count += $this->count($query);
            foreach ((clone $query)->limit($sample)->get() as $row) {
                $samples[] = (array) $row;
            }
        }

        return [
            'code' => $code, 'title' => $title, 'status' => $count === 0 ? 'pass' : 'fail', 'expected' => null, 'actual' => null, 'variance' => null,
            'violations' => $count, 'samples' => array_slice($samples, 0, $sample), 'note' => $note, 'classification' => null,
        ];
    }

    /**
     * Pure labelling of an EXACT variance — it never changes pass/fail. A variance whose absolute value is below
     * the currency settlement threshold (0.0050, Money::payableThresholdSql) is a sub-centime residue; anything
     * larger is material. Any non-zero variance is still reported as a failure.
     */
    private function classify(string $variance): string
    {
        if (Decimal::compare($variance, '0') === 0) {
            return 'none';
        }
        $abs = Decimal::compare($variance, '0') < 0 ? Decimal::subtract('0', $variance) : $variance;

        return Decimal::compare($abs, '0.0050') < 0 ? 'sub_centime_residue' : 'material';
    }

    private function count(Builder $query): int
    {
        return (int) DB::query()->fromSub($query, 'v')->count();
    }

    /** @param list<array<string, mixed>> $violations */
    private function violationResult(string $code, string $title, array $violations, int $sample, ?string $note): array
    {
        return [
            'code' => $code, 'title' => $title, 'status' => $violations === [] ? 'pass' : 'fail',
            'expected' => null, 'actual' => null, 'variance' => null,
            'violations' => count($violations), 'samples' => array_slice($violations, 0, $sample), 'note' => $note, 'classification' => null,
        ];
    }

    private function equality(string $code, string $title, string $expected, string $actual, ?string $note = null): array
    {
        $variance = Decimal::subtract($actual, $expected);
        $ok = Decimal::compare($variance, '0') === 0;

        return [
            'code' => $code, 'title' => $title, 'status' => $ok ? 'pass' : 'fail',
            'expected' => $expected, 'actual' => $actual, 'variance' => $variance,
            'violations' => $ok ? 0 : 1, 'samples' => [], 'note' => $note,
            'classification' => $this->classify($variance),
        ];
    }

    private function skipped(string $code, string $title, string $note): array
    {
        return ['code' => $code, 'title' => $title, 'status' => 'skipped', 'expected' => null, 'actual' => null,
            'variance' => null, 'violations' => 0, 'samples' => [], 'note' => $note, 'classification' => null];
    }
}
