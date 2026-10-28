<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionType;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\AttentionItem;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\SalesOrder;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\Procurement\SalesOrderProcurementPlanner;
use App\Services\SalesOrderPaymentCalculator;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * W4.5 canonical receivables attention on confirmed Sales Orders — detection
 * only; never touches orders, payments or invoices, never contacts anyone.
 *
 * Real receivables live here: an invoice can only be issued once its order is
 * fully paid, so post-issuance anomalies stay W4.4 invoice attention and this
 * detector only looks at confirmed orders WITHOUT an issued invoice (drafts
 * are not commitments, cancelled orders owe nothing). Payment truth is
 * SalesOrderPaymentCalculator::summaries (posted payments − posted refunds vs
 * the commercial total net of received returns, Decimal, batched) — never
 * the persisted payment_status. due_date is the business-local date stamped
 * at confirmation; all day counts use the organization timezone.
 *
 * - sales_order_payment_due_soon (warning): balance due today … + N days.
 * - sales_order_unpaid_overdue (critical): past due, nothing paid.
 * - sales_order_partial_overdue (critical): past due, partly paid.
 * - sales_order_payment_followup_backlog (warning / critical): order-linked
 *   open overdue follow-ups (≥ N, or one ≥ M days) on an order still owing.
 * - sales_order_paid_not_invoiced (warning, operational): fully paid manual /
 *   e-commerce order (POS counter sales are never invoiced — excluded) that
 *   can be invoiced (no commercial revision in progress) and still has no
 *   issued invoice ≥ N local days after its last posted payment (the payment
 *   that completed it; no "fully paid at" is persisted). Never escalates.
 * At most one of the payment states holds per order, so payments, refunds,
 * reversals and invoicing move it from one episode to the next.
 *
 * - sales_order_procurement_required (warning, operational, P1): a draft or
 *   confirmed order still has lines to order from a known supplier (not yet
 *   wholly on a purchase order). Closes once they are ordered.
 * - sales_order_procurement_unresolved (warning, operational, P1): a line
 *   internal stock does not cover has no usable supplier (none confirmed,
 *   supplier pending or unavailable). Closes once the line is covered by stock,
 *   has a confirmed supplier, or is ordered / received.
 * - sales_order_procurement_awaiting_receipt (warning → critical, P2): ordered
 *   supplier goods not (all) received yet. Critical once the earliest expected
 *   delivery date of a purchase order still awaiting goods has passed (the
 *   escalation clock starts then). Closes once everything ordered is received.
 *   All three come from SalesOrderProcurementPlanner (the order page's own view),
 *   one episode per order and type, independent of the payment states.
 */
final class SalesOrderAttentionDetector
{
    private const CHUNK = 200;

    private const COLUMNS = ['id', 'organization_id', 'store_id', 'customer_id', 'order_number', 'source', 'status', 'currency_code',
        'total_incl_tax', 'due_date', 'current_revision_id', 'created_by_user_id', 'confirmed_by_user_id'];

    public function __construct(
        private readonly FollowUpQuery $followUps,
        private readonly AttentionEpisodes $episodes,
        private readonly SalesOrderPaymentCalculator $payments,
        private readonly SalesOrderProcurementPlanner $procurement,
    ) {}

    /** @return array{detected: int, resolved: int, failed: int} */
    public function scanOrganization(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $today = BusinessTime::today($organization);
        // Exclusive upper bound: correct for 'Y-m-d' and cast-written 'Y-m-d 00:00:00' DATE values.
        $beforeDay = CarbonImmutable::parse($today, 'UTC')->addDays((int) config('attention.sales_orders.due_soon_days') + 1)->toDateString();
        $result = ['detected' => 0, 'resolved' => 0, 'failed' => 0];

        // Candidates: confirmed, not invoiced, and either due within the window or a possible paid-not-invoiced order.
        $this->notInvoiced(SalesOrder::query()->where('organization_id', $organization->getKey())->where('status', SalesOrderStatus::Confirmed->value))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $due) => $due->whereNotNull('due_date')->where('due_date', '<', $beforeDay))
                ->orWhere('source', '!=', SalesOrderSource::Pos->value))
            ->select(self::COLUMNS)
            ->chunkById(self::CHUNK, function (Collection $orders) use ($organization, $now, $today, &$result) {
                $summaries = $this->payments->summaries($orders);
                $revisions = $this->revisionStatuses($orders);
                $conditions = [];
                foreach ($orders as $order) {
                    $this->episodes->guard($result, function () use ($order, $summaries, $revisions, $today, &$conditions) {
                        foreach ($this->orderConditions($order, $summaries[$order->getKey()] ?? null, $revisions, $today) as $condition) {
                            $conditions[] = $this->condition($order, ...$condition);
                        }
                    });
                }
                $result['detected'] += $this->episodes->apply($organization, $conditions, $now, $result);
            });

        $this->episodes->guard($result, function () use ($organization, $today, $now, &$result) {
            $result['detected'] += $this->detectBacklog($organization, $today, $now, $result);
        });

        $this->episodes->guard($result, function () use ($organization, $today, $now, &$result) {
            $result['detected'] += $this->detectProcurement($organization, $today, $now, $result);
        });

        if ($result['failed'] === 0) {
            $result['resolved'] = $this->episodes->closeUnconfirmed($organization, AttentionItem::SUBJECT_SALES_ORDER, $now);
        }

        return $result;
    }

    /** The severity the item's condition has right now (NULL = no longer holds), same rules as the scan. */
    public function currentSeverity(Organization $organization, AttentionItem $item): ?AttentionSeverity
    {
        if ($item->active_key === null || $item->sales_order_id === null) {
            return null;
        }
        if (in_array($item->type, [AttentionType::SalesOrderProcurementRequired, AttentionType::SalesOrderProcurementUnresolved, AttentionType::SalesOrderProcurementAwaitingReceipt], true)) {
            $order = $this->procurementCandidates($organization)->whereKey($item->sales_order_id)->first();
            $holds = $order ? collect($this->procurementConditions($organization, $order, BusinessTime::today($organization)))
                ->first(fn (array $condition) => $condition[0] === $item->type) : null;

            return $holds[1] ?? null;
        }
        $order = $this->notInvoiced(SalesOrder::query()->where('organization_id', $organization->getKey())->where('status', SalesOrderStatus::Confirmed->value))
            ->whereKey($item->sales_order_id)->first(self::COLUMNS);
        if (! $order) {
            return null;
        }
        $summary = $this->payments->summaries(collect([$order]))[$order->getKey()] ?? null;
        $today = BusinessTime::today($organization);

        if ($item->type === AttentionType::SalesOrderPaymentFollowUpBacklog) {
            $group = $this->owes($summary) ? $this->backlogGroups($organization, $today, [(int) $order->getKey()])->first() : null;

            return $group ? $this->backlogSeverity($group) : null;
        }
        foreach ($this->orderConditions($order, $summary, $this->revisionStatuses(collect([$order])), $today) as [$type, $severity]) {
            if ($type === $item->type) {
                return $severity;
            }
        }

        return null;
    }

    /**
     * W4.5 transparent urgency within a severity: days left subtracted from
     * the window (due soon), days overdue (unpaid / partial), oldest overdue
     * follow-up days × 100 + count (backlog), days since the last payment
     * (paid, not invoiced).
     *
     * @param array<string, mixed> $facts
     */
    public static function urgency(AttentionType $type, array $facts): int
    {
        $days = max(0, (int) ($facts['days'] ?? 0));

        return match ($type) {
            AttentionType::SalesOrderPaymentDueSoon => max(0, (int) config('attention.sales_orders.due_soon_days') - $days),
            AttentionType::SalesOrderPaymentFollowUpBacklog => $days * 100 + min(99, (int) ($facts['count'] ?? 0)),
            default => $days,
        };
    }

    /**
     * @param array<string, mixed>|null $summary
     * @param array<int, string> $revisions
     * @return list<array{0: AttentionType, 1: AttentionSeverity, 2: array<string, mixed>}>
     */
    private function orderConditions(SalesOrder $order, ?array $summary, array $revisions, string $today): array
    {
        if ($summary === null) {
            return [];
        }
        $days = fn (string $from, string $to) => (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), false);

        if ($this->owes($summary)) {
            if ($order->due_date === null) {
                return [];
            }
            $due = $order->due_date->format('Y-m-d');
            if ($due < $today) {
                $facts = ['date' => $due, 'days' => $days($due, $today)];
                if (Decimal::compare($summary['paid'], '0') <= 0) {
                    return [[AttentionType::SalesOrderUnpaidOverdue, AttentionSeverity::Critical, $facts]];
                }

                return [[AttentionType::SalesOrderPartialOverdue, AttentionSeverity::Critical,
                    [...$facts, 'outstanding' => (string) $summary['remaining'], 'currency' => (string) $order->currency_code]]];
            }
            if ($days($today, $due) <= (int) config('attention.sales_orders.due_soon_days')) {
                return [[AttentionType::SalesOrderPaymentDueSoon, AttentionSeverity::Warning, ['date' => $due, 'days' => $days($today, $due)]]];
            }

            return [];
        }

        // Fully paid and still not invoiced (the query already excluded issued invoices).
        $invoiceable = $order->source !== SalesOrderSource::Pos
            && Decimal::compare($summary['commercial_total'], '0') > 0
            && Decimal::compare($summary['paid'], $summary['commercial_total']) >= 0
            && ($order->current_revision_id === null || ($revisions[(int) $order->current_revision_id] ?? null) === 'completed')
            && $summary['last_payment_date'] !== null;
        if ($invoiceable) {
            $paidOn = (string) $summary['last_payment_date'];
            if ($days($paidOn, $today) >= (int) config('attention.sales_orders.paid_not_invoiced_days')) {
                return [[AttentionType::SalesOrderPaidNotInvoiced, AttentionSeverity::Warning, ['date' => $paidOn, 'days' => $days($paidOn, $today)]]];
            }
        }

        return [];
    }

    /** @param array<string, int> $result */
    private function detectBacklog(Organization $organization, string $today, CarbonImmutable $now, array &$result): int
    {
        $detected = 0;
        foreach ($this->backlogGroups($organization, $today)->chunk(self::CHUNK) as $chunk) {
            $orders = $this->notInvoiced(SalesOrder::query()->where('organization_id', $organization->getKey())->where('status', SalesOrderStatus::Confirmed->value))
                ->whereKey($chunk->pluck('sales_order_id')->all())->get(self::COLUMNS)->keyBy('id');
            $summaries = $this->payments->summaries($orders);
            $conditions = [];
            foreach ($chunk as $group) {
                $order = $orders->get($group['sales_order_id']);
                if (! $order || ! $this->owes($summaries[$order->getKey()] ?? null)) {
                    continue;
                }
                $conditions[] = $this->condition($order, AttentionType::SalesOrderPaymentFollowUpBacklog, $this->backlogSeverity($group),
                    ['count' => $group['count'], 'date' => $group['date'], 'days' => $group['days']]);
            }
            $detected += $this->episodes->apply($organization, $conditions, $now, $result);
        }

        return $detected;
    }

    /**
     * Order-linked overdue follow-up groups meeting the backlog threshold.
     *
     * @param list<int>|null $orderIds
     * @return Collection<int, array{sales_order_id: int, count: int, date: string, days: int}>
     */
    private function backlogGroups(Organization $organization, string $today, ?array $orderIds = null): Collection
    {
        $config = config('attention.sales_orders');
        $overdue = FollowUp::query()->where('organization_id', $organization->getKey())->whereNotNull('sales_order_id')
            ->when($orderIds !== null, fn ($query) => $query->whereIn('sales_order_id', $orderIds));
        $this->followUps->applyBucket($overdue, $organization, 'overdue');

        return $overdue->groupBy('sales_order_id')
            ->selectRaw('sales_order_id, COUNT(*) as overdue_count, MIN(due_date) as oldest_due')
            ->orderBy('sales_order_id')->get()
            ->map(fn ($row) => ['sales_order_id' => (int) $row->sales_order_id, 'count' => (int) $row->overdue_count,
                'date' => (string) $row->oldest_due, 'days' => (int) CarbonImmutable::parse((string) $row->oldest_due, 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC'), false)])
            ->filter(fn (array $group) => $group['count'] >= (int) $config['followup_backlog_count'] || $group['days'] >= (int) $config['followup_backlog_age_days'])
            ->values();
    }

    /** @param array<string, int> $result */
    private function detectProcurement(Organization $organization, string $today, CarbonImmutable $now, array &$result): int
    {
        $detected = 0;
        $this->procurementCandidates($organization)->chunkById(self::CHUNK, function (Collection $orders) use ($organization, $today, $now, &$result, &$detected) {
            $conditions = [];
            foreach ($orders as $order) {
                $this->episodes->guard($result, function () use ($order, $organization, $today, &$conditions) {
                    foreach ($this->procurementConditions($organization, $order, $today) as [$type, $severity, $facts]) {
                        $conditions[] = $this->condition($order, $type, $severity, $facts);
                    }
                });
            }
            $detected += $this->episodes->apply($organization, $conditions, $now, $result);
        });

        return $detected;
    }

    /**
     * Draft / confirmed orders where supplier procurement is in play: a live
     * requirement, or a draft converted from a quotation (its requirements may
     * still have to be derived, or a line may lack a supplier).
     */
    private function procurementCandidates(Organization $organization): Builder
    {
        return SalesOrder::query()->where('organization_id', $organization->getKey())
            ->whereIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Confirmed->value])
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (QueryBuilder $procurements) => $procurements->selectRaw('1')->from('sales_order_procurements')
                    ->whereColumn('sales_order_procurements.organization_id', 'sales_orders.organization_id')
                    ->whereColumn('sales_order_procurements.sales_order_id', 'sales_orders.id')
                    ->whereIn('sales_order_procurements.status', [SupplierProcurementStatus::PendingSupplier->value,
                        SupplierProcurementStatus::SupplierConfirmed->value, SupplierProcurementStatus::Unavailable->value,
                        SupplierProcurementStatus::Ordered->value, SupplierProcurementStatus::PartiallyReceived->value]))
                ->orWhere(fn (Builder $draft) => $draft->where('status', SalesOrderStatus::Draft->value)
                    ->whereExists(fn (QueryBuilder $quotations) => $quotations->selectRaw('1')->from('quotations')
                        ->whereColumn('quotations.organization_id', 'sales_orders.organization_id')
                        ->whereColumn('quotations.converted_sales_order_id', 'sales_orders.id'))));
    }

    /**
     * The order's procurement readiness, exactly as the order page shows it
     * (SalesOrderProcurementPlanner): lines still to order with a known supplier,
     * and lines with no usable supplier. Dated from the order's creation.
     *
     * @return list<array{0: AttentionType, 1: AttentionSeverity, 2: array<string, mixed>}>
     */
    private function procurementConditions(Organization $organization, SalesOrder $order, string $today): array
    {
        if (! in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true)) {
            return [];
        }
        $plan = $this->procurement->plan($order);
        $summary = $plan['summary'];
        $date = CarbonImmutable::parse($order->created_at, 'UTC')->setTimezone($organization->businessTimezone())->toDateString();
        $daysBetween = fn (string $from, string $to) => (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), false);
        $days = max(0, $daysBetween($date, $today));
        $conditions = [];
        if ($summary['to_order_line_count'] > 0) {
            $conditions[] = [AttentionType::SalesOrderProcurementRequired, AttentionSeverity::Warning, ['count' => $summary['to_order_line_count'], 'date' => $date, 'days' => $days]];
        }
        if ($summary['unresolved_count'] > 0) {
            $conditions[] = [AttentionType::SalesOrderProcurementUnresolved, AttentionSeverity::Warning, ['count' => $summary['unresolved_count'], 'date' => $date, 'days' => $days]];
        }

        // Ordered goods still expected: the earliest expected delivery of a purchase
        // order line still awaiting goods dates it; past that date it is critical.
        $awaiting = collect($plan['lines'])->filter(fn (array $line) => in_array($line['state'], [SalesOrderProcurementPlanner::ORDERED, SalesOrderProcurementPlanner::PARTIALLY_RECEIVED], true));
        if ($awaiting->isNotEmpty()) {
            $expected = $awaiting->flatMap(fn (array $line) => $line['purchase_orders'])
                ->filter(fn (array $po) => Decimal::compare($po['remaining_to_receive'], '0') > 0 && $po['expected_delivery_date'] !== null)
                ->pluck('expected_delivery_date')->sort()->first();
            $overdue = $expected !== null && $expected < $today;
            $conditions[] = [AttentionType::SalesOrderProcurementAwaitingReceipt, $overdue ? AttentionSeverity::Critical : AttentionSeverity::Warning, [
                'count' => $awaiting->count(),
                'date' => $expected ?? $date,
                'days' => $overdue ? $daysBetween($expected, $today) : ($expected !== null ? max(0, $daysBetween($today, $expected)) : $days),
                'overdue' => $overdue,
                'expected' => $expected !== null,
            ]];
        }

        return $conditions;
    }

    /** @param array{count: int, days: int} $group */
    private function backlogSeverity(array $group): AttentionSeverity
    {
        $config = config('attention.sales_orders');

        return $group['count'] >= (int) $config['followup_backlog_critical_count'] || $group['days'] >= (int) $config['followup_backlog_critical_age_days']
            ? AttentionSeverity::Critical : AttentionSeverity::Warning;
    }

    /** Confirmed orders with no issued invoice (one correlated NOT EXISTS, no per-order query). */
    private function notInvoiced(Builder $query): Builder
    {
        return $query->whereNotExists(fn (QueryBuilder $invoices) => $invoices->selectRaw('1')->from('invoices')
            ->whereColumn('invoices.organization_id', 'sales_orders.organization_id')
            ->whereColumn('invoices.sales_order_id', 'sales_orders.id')
            ->where('invoices.status', InvoiceStatus::Issued->value));
    }

    /** Current commercial revision statuses for a chunk (one query). @return array<int, string> */
    private function revisionStatuses(Collection $orders): array
    {
        $ids = $orders->pluck('current_revision_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : DB::table('sales_order_revisions')->whereIn('id', $ids)->pluck('status', 'id')
            ->mapWithKeys(fn ($status, $id) => [(int) $id => (string) $status])->all();
    }

    /** @param array<string, mixed>|null $summary */
    private function owes(?array $summary): bool
    {
        return $summary !== null && Decimal::compare($summary['remaining'], '0') > 0;
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private function condition(SalesOrder $order, AttentionType $type, AttentionSeverity $severity, array $facts): array
    {
        [$title, $summary, $i18n] = $this->text($type, $order, $facts);

        return ['subject_id' => (int) $order->getKey(), 'store_id' => $order->store_id, 'customer_id' => $order->customer_id,
            'sales_order_id' => (int) $order->getKey(), 'number' => $order->order_number, 'type' => $type, 'severity' => $severity,
            'facts' => $facts, 'title' => $title, 'summary' => $summary, 'i18n' => $i18n, 'urgency' => self::urgency($type, $facts)];
    }

    /** Stored French text + i18n keys. Amounts only in the partial summary (visible to order viewers only). @param array<string, mixed> $facts @return array{0: string, 1: string, 2: array<string, mixed>} */
    private function text(AttentionType $type, SalesOrder $order, array $facts): array
    {
        $number = $order->order_number;
        $date = CarbonImmutable::parse((string) $facts['date'], 'UTC')->format('d/m/Y');
        // Display only: Decimal rounds at 2 places, then the 4-place internal scale is cut (no floats).
        $amount = isset($facts['outstanding']) ? preg_replace('/(\.\d{2})\d*$/', '$1', Decimal::round($facts['outstanding'], 2)).' '.$facts['currency'] : '';
        $params = ['number' => $number, 'date' => $date, 'days' => (string) $facts['days'], 'count' => (string) ($facts['count'] ?? ''), 'amount' => $amount];
        [$key, $title, $summary] = match ($type) {
            AttentionType::SalesOrderPaymentDueSoon => ['orderDueSoon', "Commande {$number} à échéance proche", "Échéance le {$date} — {$facts['days']} jour(s) restant(s), solde non réglé."],
            AttentionType::SalesOrderUnpaidOverdue => ['orderUnpaidOverdue', "Commande {$number} impayée en retard", "Échéance dépassée depuis le {$date} ({$facts['days']} j), aucun paiement."],
            AttentionType::SalesOrderPartialOverdue => ['orderPartialOverdue', "Commande {$number} partiellement payée en retard", "Échéance dépassée depuis le {$date} ({$facts['days']} j) — reste {$amount}."],
            AttentionType::SalesOrderPaymentFollowUpBacklog => ['orderBacklog', "Relances de paiement en retard — commande {$number}", "{$facts['count']} suivi(s) en retard, le plus ancien prévu le {$date} ({$facts['days']} j)."],
            AttentionType::SalesOrderPaidNotInvoiced => ['orderPaidNotInvoiced', "Commande {$number} payée, non facturée", "Réglée depuis le {$date} ({$facts['days']} j), aucune facture émise."],
            AttentionType::SalesOrderProcurementRequired => ['orderProcurementRequired', "Approvisionnement requis pour {$number}", "{$facts['count']} article(s) à commander auprès d’un fournisseur (commande du {$date})."],
            AttentionType::SalesOrderProcurementAwaitingReceipt => match (true) {
                (bool) ($facts['overdue'] ?? false) => ['orderAwaitingReceiptOverdue', "En attente de réception fournisseur pour {$number}", "{$facts['count']} article(s) attendu(s) du fournisseur : livraison prévue le {$date} dépassée ({$facts['days']} j)."],
                (bool) ($facts['expected'] ?? false) => ['orderAwaitingReceipt', "En attente de réception fournisseur pour {$number}", "{$facts['count']} article(s) attendu(s) du fournisseur, livraison prévue le {$date}."],
                default => ['orderAwaitingReceiptUndated', "En attente de réception fournisseur pour {$number}", "{$facts['count']} article(s) attendu(s) du fournisseur (commande du {$date})."],
            },
            AttentionType::SalesOrderProcurementUnresolved => ['orderProcurementUnresolved', "Fournisseur requis pour {$number}", "{$facts['count']} article(s) sans fournisseur confirmé : choisir un fournisseur ou couvrir depuis le stock (commande du {$date})."],
            default => throw new LogicException('Not a sales order attention type.'),
        };

        return [$title, $summary, ['title' => "attention.{$key}Title", 'message' => "attention.{$key}Summary", 'params' => $params]];
    }
}
