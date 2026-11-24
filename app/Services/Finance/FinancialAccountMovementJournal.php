<?php

namespace App\Services\Finance;

use App\Models\FinancialAccount;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only per-account movement journal (FIN-AUDIT-002).
 *
 * There is NO stored movement ledger: the journal is a UNION ALL projection of the
 * four records the authoritative balance already uses (payments, payment_refunds,
 * finance_outgoing_payments, financial_account_opening_positions) with the SAME
 * inclusion rules as FinancialAccountBalanceService / reconciliation rule R-2:
 *
 *   opening + posted customer payments - posted refunds - posted outgoing payments,
 *   business date BETWEEN the opening effective date AND the as-of date.
 *
 * Reversals are in-place status changes on the source row (no offsetting record is
 * persisted). The journal therefore shows TWO linked entries for a reversed payment:
 * the original entry and a derived reversal entry. Both carry the SAME `flag_date`
 * (the original payment_date, the date the balance service uses) and therefore the same
 * balance inclusion, so the pair always nets to zero and the journal total equals the
 * existing balance. The reversal entry's own date is the reversal instant converted to the
 * organization business timezone (never earlier than the original payment date).
 *
 * The running balance is a cumulative of the journal in display order. It is NOT a
 * historical as-of balance: between a payment and its reversal it differs from
 * FinancialAccountBalanceService::organizationPosition(as_of) (which never counts a reversed
 * payment). It is exposed only when the full journal reconciles to the current balance.
 *
 * Only Production tables are read (raw table queries, explicit organization_id everywhere).
 */
class FinancialAccountMovementJournal
{
    public const TYPES = [
        'opening_balance', 'customer_payment', 'customer_payment_reversal',
        'customer_refund', 'supplier_payment', 'supplier_payment_reversal',
    ];

    public const EXPORT_MAX_ROWS = 20000;

    private const EPOCH = '1970-01-01 00:00:00';

    public function __construct(private readonly FinancialAccountBalanceService $balances) {}

    /**
     * @return array{asOf:string,effective:?string,configured:bool,balance:?string,opening_amount:?string,currency_code:string,reversalDates:array<int,array<string,string>>}
     */
    public function context(Organization $organization, FinancialAccount $account, CarbonInterface $asOf): array
    {
        $position = $this->balances->organizationPosition($organization, $asOf, $account->getKey())['accounts'][0] ?? null;

        return [
            'asOf' => $asOf->toDateString(),
            'effective' => $position['effective_date'] ?? null,
            'configured' => (bool) ($position['configured'] ?? false),
            'balance' => $position['balance'] ?? null,
            'opening_amount' => $position['opening_amount'] ?? null,
            'currency_code' => (string) $account->currency_code,
            'reversalDates' => [
                'payments' => $this->reversalDates($organization, $account, 'payments', 'payment_date'),
                'finance_outgoing_payments' => $this->reversalDates($organization, $account, 'finance_outgoing_payments', 'payment_date'),
            ],
        ];
    }

    /**
     * Sum of every in-balance entry of the (unfiltered) journal and whether it equals the existing
     * authoritative balance. The running balance is only trusted when this is true.
     *
     * @param  array<string,mixed>  $ctx
     * @return array{total:?string,reconciled:bool}
     */
    public function reconcile(Organization $organization, FinancialAccount $account, array $ctx): array
    {
        if (! $ctx['configured']) {
            return ['total' => null, 'reconciled' => false];
        }
        $sum = DB::query()->fromSub($this->entries($organization, $account, $ctx), 'e')
            ->selectRaw('COALESCE(SUM(CASE WHEN e.in_balance = 1 THEN e.effect ELSE 0 END), 0) as s')->value('s');
        $total = $this->money($sum);

        return ['total' => $total, 'reconciled' => Decimal::compare($total, (string) $ctx['balance']) === 0];
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>  $filters
     */
    public function page(Organization $organization, FinancialAccount $account, array $ctx, array $filters, User $user, int $page, int $perPage, bool $withRunning, string $path): LengthAwarePaginator
    {
        $query = $this->filtered($this->entries($organization, $account, $ctx), $filters);
        $total = (clone $query)->count();
        $rows = $this->ordered($query)->forPage($page, $perPage)->get()->map(fn ($r) => (array) $r)->all();
        $items = $this->decorate($organization, $account, $ctx, $rows, $user, $withRunning);

        return new LengthAwarePaginator($items, $total, $perPage, $page, ['path' => $path]);
    }

    /**
     * Streams the SAME rows (same filters, order, decoration, running balance) in batches, for exports.
     *
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>  $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function stream(Organization $organization, FinancialAccount $account, array $ctx, array $filters, User $user, bool $withRunning): \Generator
    {
        $rows = $this->ordered($this->filtered($this->entries($organization, $account, $ctx), $filters))
            ->limit(self::EXPORT_MAX_ROWS)->get()->map(fn ($r) => (array) $r)->all();
        if ($rows === []) {
            return;
        }
        if ($withRunning) {
            $wanted = array_fill_keys(array_map(fn ($r) => $r['source_type'].':'.$r['source_id'], $rows), true);
            $running = '0.0000';
            $map = [];
            $light = DB::query()->fromSub($this->entries($organization, $account, $ctx), 'e')
                ->orderBy('e.business_date')->orderBy('e.rank0')->orderBy('e.sort_ts')->orderBy('e.src_rank')->orderBy('e.source_id')
                ->select('e.source_type', 'e.source_id', 'e.effect', 'e.in_balance');
            foreach ($light->cursor() as $entry) {
                if ((int) $entry->in_balance === 1) {
                    $running = Decimal::add($running, $this->money($entry->effect));
                }
                $key = $entry->source_type.':'.$entry->source_id;
                if (isset($wanted[$key])) {
                    $map[$key] = (int) $entry->in_balance === 1 ? $running : null;
                }
            }
            foreach ($rows as &$row) {
                $row['_running'] = $map[$row['source_type'].':'.$row['source_id']] ?? null;
            }
            unset($row);
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            yield from $this->decorate($organization, $account, $ctx, $chunk, $user, $withRunning, true);
        }
    }

    /** @param array<string,mixed> $ctx @param array<string,mixed> $filters */
    public function count(Organization $organization, FinancialAccount $account, array $ctx, array $filters): int
    {
        return $this->filtered($this->entries($organization, $account, $ctx), $filters)->count();
    }

    // ---------------------------------------------------------------- query

    /** @param array<string,mixed> $ctx */
    private function entries(Organization $organization, FinancialAccount $account, array $ctx): Builder
    {
        $org = $organization->getKey();
        $acc = $account->getKey();
        $cols = static fn (string $type, string $id, string $date, string $flag, string $ts, int $srcRank, int $rank0, string $dir, string $amount, string $effect, string $status, string $store, string $user, string $ref, string $ext, string $method, string $link, string $currency): string => "'{$type}' as source_type, {$id} as source_id, {$date} as business_date, {$flag} as flag_date, {$ts} as sort_ts, {$srcRank} as src_rank, {$rank0} as rank0, {$dir} as direction, {$amount} as amount, {$effect} as effect, {$currency} as currency_code, {$status} as status, {$store} as store_id, {$user} as user_id, {$ref} as reference, {$ext} as external_reference, {$method} as method, {$link} as link_id";

        $opening = DB::table('financial_account_opening_positions as op')
            ->where('op.organization_id', $org)->where('op.financial_account_id', $acc)
            ->selectRaw($cols('opening_balance', 'op.id', 'DATE(op.effective_date)', 'DATE(op.effective_date)', "COALESCE(op.created_at, '".self::EPOCH."')", 0, 0,
                "CASE WHEN op.amount >= 0 THEN 'in' ELSE 'out' END", 'ABS(op.amount)', 'op.amount', "'posted'", 'NULL', 'op.created_by_user_id', 'op.reference', 'NULL', 'NULL', 'NULL', 'op.currency_code'));

        $payments = DB::table('payments as p')->where('p.organization_id', $org)->where('p.financial_account_id', $acc)->whereIn('p.status', ['posted', 'reversed'])
            ->selectRaw($cols('customer_payment', 'p.id', 'DATE(p.payment_date)', 'DATE(p.payment_date)', "COALESCE(p.created_at, '".self::EPOCH."')", 1, 1,
                "'in'", 'p.amount', 'p.amount', 'p.status', 'p.store_id', 'p.received_by_user_id', 'p.payment_number', 'p.external_reference', 'p.method', 'NULL', 'p.currency_code'));

        $refunds = DB::table('payment_refunds as r')->where('r.organization_id', $org)->where('r.financial_account_id', $acc)->where('r.status', 'posted')
            ->selectRaw($cols('customer_refund', 'r.id', 'DATE(r.refund_date)', 'DATE(r.refund_date)', "COALESCE(r.created_at, '".self::EPOCH."')", 3, 1,
                "'out'", 'r.amount', '(0 - r.amount)', 'r.status', 'r.store_id', 'r.refunded_by_user_id', 'r.refund_number', 'NULL', 'r.method', 'r.payment_id', 'r.currency_code'));

        $outgoing = DB::table('finance_outgoing_payments as o')->where('o.organization_id', $org)->where('o.financial_account_id', $acc)->whereIn('o.status', ['posted', 'reversed'])
            ->selectRaw($cols('supplier_payment', 'o.id', 'DATE(o.payment_date)', 'DATE(o.payment_date)', "COALESCE(o.created_at, '".self::EPOCH."')", 4, 1,
                "'out'", 'o.amount', '(0 - o.amount)', 'o.status', 'o.store_id', 'o.recorded_by_user_id', 'o.payment_number', 'o.reference', 'o.payment_method', 'NULL', 'o.currency_code'));

        $union = $opening->unionAll($payments)->unionAll($refunds)->unionAll($outgoing);

        foreach ([
            ['payments', 'p', 'customer_payment_reversal', 2, 'out', 'p.received_by_user_id', 'p.method', '(0 - p.amount)', 'p.payment_number', 'p.external_reference', 'p.reversed_by_user_id'],
            ['finance_outgoing_payments', 'o', 'supplier_payment_reversal', 5, 'in', 'o.recorded_by_user_id', 'o.payment_method', 'o.amount', 'o.payment_number', 'o.reference', 'o.reversed_by_user_id'],
        ] as [$table, $a, $type, $srcRank, $dir, , $method, $effect, $number, $ext, $by]) {
            $dates = $ctx['reversalDates'][$table];
            if ($dates === []) {
                continue;
            }
            $case = 'CASE '.$a.'.id';
            $bindings = [];
            foreach ($dates as $id => $date) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = (int) $id;
                $bindings[] = $date;
            }
            $case .= " ELSE DATE({$a}.payment_date) END";
            $reversal = DB::table("{$table} as {$a}")->where("{$a}.organization_id", $org)->where("{$a}.financial_account_id", $acc)->where("{$a}.status", 'reversed')
                ->selectRaw($cols($type, "{$a}.id", $case, "DATE({$a}.payment_date)", "COALESCE({$a}.reversed_at, {$a}.created_at, '".self::EPOCH."')", $srcRank, 1,
                    "'{$dir}'", "{$a}.amount", $effect, "'reversed'", "{$a}.store_id", $by, $number, $ext, $method, "{$a}.id", "{$a}.currency_code"), $bindings);
            $union->unionAll($reversal);
        }

        $query = DB::query()->fromSub($union, 'u');
        if (($ctx['effective'] ?? null) === null) {
            return $query->selectRaw('u.*, 0 as in_balance');
        }

        return $query->selectRaw('u.*, CASE WHEN u.flag_date >= ? AND u.flag_date <= ? THEN 1 ELSE 0 END as in_balance', [$ctx['effective'], $ctx['asOf']]);
    }

    /** @param array<string,mixed> $filters */
    private function filtered(Builder $query, array $filters): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->where('u.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('u.business_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['type'])) {
            $query->where('u.source_type', $filters['type']);
        }
        if (! empty($filters['store_id'])) {
            $query->where('u.store_id', (int) $filters['store_id']);
        }
        if (isset($filters['reference']) && trim((string) $filters['reference']) !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim((string) $filters['reference'])).'%';
            $query->where(fn ($q) => $q->whereRaw("u.reference LIKE ? ESCAPE '!'", [$like])->orWhereRaw("u.external_reference LIKE ? ESCAPE '!'", [$like]));
        }

        return $query;
    }

    private function ordered(Builder $query): Builder
    {
        return $query->orderBy('u.business_date')->orderBy('u.rank0')->orderBy('u.sort_ts')->orderBy('u.src_rank')->orderBy('u.source_id');
    }

    /**
     * Local (organization business timezone) date of every reversal, never earlier than the payment date.
     *
     * @return array<int,string>
     */
    private function reversalDates(Organization $organization, FinancialAccount $account, string $table, string $dateColumn): array
    {
        $out = [];
        $rows = DB::table($table)->where('organization_id', $organization->getKey())->where('financial_account_id', $account->getKey())
            ->where('status', 'reversed')->get(['id', $dateColumn.' as paid', 'reversed_at']);
        foreach ($rows as $row) {
            $paid = substr((string) $row->paid, 0, 10);
            $local = $row->reversed_at === null
                ? $paid
                : BusinessTime::toLocal($organization, CarbonImmutable::parse((string) $row->reversed_at, 'UTC'))->toDateString();
            $out[(int) $row->id] = max($paid, $local);
        }

        return $out;
    }

    // ------------------------------------------------------------ decoration

    /**
     * @param  array<string,mixed>  $ctx
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function decorate(Organization $organization, FinancialAccount $account, array $ctx, array $rows, User $user, bool $withRunning, bool $runningPrecomputed = false): array
    {
        if ($rows === []) {
            return [];
        }
        $running = ($withRunning && ! $runningPrecomputed) ? $this->runningFor($organization, $account, $ctx, $rows) : [];
        $org = $organization->getKey();
        $ids = fn (array $types) => collect($rows)->whereIn('source_type', $types)->pluck('source_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $payIds = $ids(['customer_payment', 'customer_payment_reversal']);
        $refundIds = $ids(['customer_refund']);
        $outIds = $ids(['supplier_payment', 'supplier_payment_reversal']);

        $payDetails = $payIds === [] ? [] : DB::table('payments as p')->where('p.organization_id', $org)->whereIn('p.id', $payIds)
            ->get(['p.id', 'p.reversal_reason', 'p.reversed_at', 'p.reversed_by_user_id', 'p.notes', 'p.store_id'])->keyBy('id')->all();
        $payOrders = $payIds === [] ? collect() : DB::table('payment_allocations as a')->join('sales_orders as s', 's.id', '=', 'a.sales_order_id')
            ->where('a.organization_id', $org)->whereIn('a.payment_id', $payIds)->orderBy('a.id')
            ->get(['a.payment_id', 's.order_number', 's.customer_name', 's.customer_company'])->groupBy('payment_id');
        $refundDetails = $refundIds === [] ? [] : DB::table('payment_refunds as r')->join('sales_orders as s', 's.id', '=', 'r.sales_order_id')
            ->leftJoin('payments as p', 'p.id', '=', 'r.payment_id')->where('r.organization_id', $org)->whereIn('r.id', $refundIds)
            ->get(['r.id', 'r.reason', 'r.payment_id', 'p.payment_number as payment_number', 'p.store_id as payment_store_id', 's.order_number', 's.customer_name', 's.customer_company'])->keyBy('id')->all();
        $outDetails = $outIds === [] ? [] : DB::table('finance_outgoing_payments as o')->leftJoin('suppliers as sp', 'sp.id', '=', 'o.supplier_id')
            ->where('o.organization_id', $org)->whereIn('o.id', $outIds)
            ->get(['o.id', 'o.beneficiary_name', 'o.reversal_reason', 'o.reversed_by_user_id', 'o.notes', 'sp.name as supplier_name'])->keyBy('id')->all();
        $outObligations = $outIds === [] ? collect() : DB::table('finance_outgoing_payment_allocations as a')->join('finance_obligations as ob', 'ob.id', '=', 'a.obligation_id')
            ->where('a.organization_id', $org)->whereIn('a.outgoing_payment_id', $outIds)->orderBy('a.id')->get(['a.outgoing_payment_id', 'ob.obligation_number', 'ob.label'])->groupBy('outgoing_payment_id');
        $instruments = $outIds === [] ? [] : DB::table('finance_payment_instruments')->where('organization_id', $org)->whereIn('outgoing_payment_id', $outIds)
            ->get(['outgoing_payment_id', 'instrument_number', 'type', 'state'])->keyBy('outgoing_payment_id')->all();

        $userIds = collect($rows)->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $userNames = $userIds === [] ? [] : DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')->all();
        $storeIds = collect($rows)->pluck('store_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $storeNames = $storeIds === [] ? [] : DB::table('stores')->where('organization_id', $org)->whereIn('id', $storeIds)->pluck('name', 'id')->all();

        // Drill-down is offered only where the existing PaymentPolicy already grants view access
        // (active store + payments.view + same environment); otherwise plain metadata.
        $viewable = [];
        $payModelIds = array_values(array_unique(array_merge($payIds, collect($refundDetails)->pluck('payment_id')->filter()->map(fn ($v) => (int) $v)->all())));
        if ($payModelIds !== []) {
            foreach (Payment::query()->where('organization_id', $org)->whereIn('id', $payModelIds)->get() as $payment) {
                $viewable[$payment->id] = Gate::forUser($user)->allows('view', $payment);
            }
        }

        $effective = $ctx['effective'];
        $out = [];
        foreach ($rows as $row) {
            $type = (string) $row['source_type'];
            $id = (int) $row['source_id'];
            $flag = (string) $row['flag_date'];
            $scope = match (true) {
                $effective === null => 'unconfigured',
                $flag < $effective => 'pre_opening',
                $flag > $ctx['asOf'] => 'after_as_of',
                default => 'in_balance',
            };
            $counterparty = null;
            $documents = [];
            $reason = null;
            $reversedBy = null;
            $reversedAt = null;
            $instrument = null;
            $url = null;
            $linked = null;
            $notes = null;
            if (in_array($type, ['customer_payment', 'customer_payment_reversal'], true)) {
                $detail = $payDetails[$id] ?? null;
                $orders = $payOrders->get($id, collect());
                $first = $orders->first();
                $counterparty = $first ? (trim((string) ($first->customer_company ?: $first->customer_name)) ?: null) : null;
                $documents = $orders->pluck('order_number')->all();
                $notes = $detail->notes ?? null;
                if ($row['status'] === 'reversed') {
                    $reason = $detail->reversal_reason ?? null;
                    $reversedAt = $detail->reversed_at ?? null;
                    $reversedBy = $userNames[$detail->reversed_by_user_id ?? 0] ?? $this->userName((int) ($detail->reversed_by_user_id ?? 0));
                    $linked = ['key' => ($type === 'customer_payment' ? 'customer_payment_reversal' : 'customer_payment').':'.$id, 'type' => $type === 'customer_payment' ? 'customer_payment_reversal' : 'customer_payment'];
                }
                $url = ($viewable[$id] ?? false) ? "/payments/{$id}" : null;
            } elseif ($type === 'customer_refund') {
                $detail = $refundDetails[$id] ?? null;
                $counterparty = $detail ? (trim((string) ($detail->customer_company ?: $detail->customer_name)) ?: null) : null;
                $documents = array_values(array_filter([$detail->order_number ?? null, $detail->payment_number ?? null]));
                $reason = $detail->reason ?? null;
                $paymentId = (int) ($detail->payment_id ?? 0);
                $url = ($viewable[$paymentId] ?? false) ? "/payments/{$paymentId}" : null;
            } elseif (in_array($type, ['supplier_payment', 'supplier_payment_reversal'], true)) {
                $detail = $outDetails[$id] ?? null;
                $counterparty = $detail ? ($detail->supplier_name ?: $detail->beneficiary_name) : null;
                $documents = $outObligations->get($id, collect())->map(fn ($o) => $o->obligation_number.' — '.$o->label)->all();
                $notes = $detail->notes ?? null;
                $inst = $instruments[$id] ?? null;
                $instrument = $inst ? ['number' => $inst->instrument_number, 'type' => $inst->type, 'state' => $inst->state] : null;
                if ($row['status'] === 'reversed') {
                    $reason = $detail->reversal_reason ?? null;
                    $linked = ['key' => ($type === 'supplier_payment' ? 'supplier_payment_reversal' : 'supplier_payment').':'.$id, 'type' => $type === 'supplier_payment' ? 'supplier_payment_reversal' : 'supplier_payment'];
                }
            }
            $isIn = $row['direction'] === 'in';
            $amount = $this->money($row['amount']);
            $key = $type.':'.$id;
            $out[] = [
                'key' => $key,
                'type' => $type,
                'date' => (string) $row['business_date'],
                'direction' => $row['direction'],
                'money_in' => $isIn ? $amount : null,
                'money_out' => $isIn ? null : $amount,
                'currency_code' => (string) $row['currency_code'],
                'status' => (string) $row['status'],
                'reference' => $row['reference'],
                'external_reference' => $row['external_reference'],
                'method' => $row['method'],
                'counterparty' => $counterparty,
                'documents' => $documents,
                'notes' => $notes,
                'reason' => $reason,
                'instrument' => $instrument,
                'store' => $row['store_id'] ? ($storeNames[(int) $row['store_id']] ?? null) : null,
                'user' => $row['user_id'] ? ($userNames[(int) $row['user_id']] ?? null) : null,
                'balance_scope' => $scope,
                'running_balance' => $withRunning ? ($runningPrecomputed ? ($row['_running'] ?? null) : ($running[$key] ?? null)) : null,
                'linked' => $linked,
                'url' => $url,
            ];
        }

        return $out;
    }

    private function userName(int $id): ?string
    {
        return $id === 0 ? null : DB::table('users')->where('id', $id)->value('name');
    }

    /**
     * Running balance of the page's rows in the UNFILTERED journal order:
     * carry-in (SQL SUM of the rows before the first page row) + exact PHP decimal accumulation across the range.
     *
     * @param  array<string,mixed>  $ctx
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,?string>
     */
    private function runningFor(Organization $organization, FinancialAccount $account, array $ctx, array $rows): array
    {
        $first = $rows[0];
        $last = $rows[array_key_last($rows)];
        $tuple = '(e.business_date, e.rank0, e.sort_ts, e.src_rank, e.source_id)';
        $firstKey = [$first['business_date'], $first['rank0'], $first['sort_ts'], $first['src_rank'], $first['source_id']];
        $lastKey = [$last['business_date'], $last['rank0'], $last['sort_ts'], $last['src_rank'], $last['source_id']];
        $entries = $this->entries($organization, $account, $ctx);

        $carry = $this->money(DB::query()->fromSub($entries, 'e')->whereRaw("{$tuple} < (?, ?, ?, ?, ?)", $firstKey)
            ->selectRaw('COALESCE(SUM(CASE WHEN e.in_balance = 1 THEN e.effect ELSE 0 END), 0) as s')->value('s'));

        $running = $carry;
        $map = [];
        $range = DB::query()->fromSub($this->entries($organization, $account, $ctx), 'e')
            ->whereRaw("{$tuple} >= (?, ?, ?, ?, ?)", $firstKey)->whereRaw("{$tuple} <= (?, ?, ?, ?, ?)", $lastKey)
            ->orderBy('e.business_date')->orderBy('e.rank0')->orderBy('e.sort_ts')->orderBy('e.src_rank')->orderBy('e.source_id')
            ->get(['e.source_type', 'e.source_id', 'e.effect', 'e.in_balance']);
        foreach ($range as $entry) {
            if ((int) $entry->in_balance === 1) {
                $running = Decimal::add($running, $this->money($entry->effect));
            }
            $map[$entry->source_type.':'.$entry->source_id] = (int) $entry->in_balance === 1 ? $running : null;
        }

        return $map;
    }

    /** Exact 4-decimal string from a SQL amount (MySQL returns decimal strings; SQLite may return floats). */
    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d{1,4})?$/', $value) === 1) {
            return Decimal::normalize($value);
        }

        return Decimal::normalize(sprintf('%.4F', (float) $value));
    }
}
