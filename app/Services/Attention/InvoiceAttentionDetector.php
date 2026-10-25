<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionType;
use App\Enums\InvoiceStatus;
use App\Models\AttentionItem;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\Finance\FinanceInvoiceReadModel;
use App\Services\FollowUps\FollowUpQuery;
use App\Support\BusinessTime;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * W4.4 canonical invoice / payment attention rules — detection only; never
 * touches invoices, payments or customers, never contacts anyone.
 *
 * Financially actionable = status issued (the current version: drafts are not
 * payable, cancelled are void, superseded were replaced by a correction) with
 * an outstanding balance. Payment truth is the Finance read model
 * (FinanceInvoiceReadModel::withPaymentSummaries: posted payments − posted
 * refunds against the invoice's sales order, net of issued credit notes,
 * Decimal arithmetic) — never a status string or a float. due_date is the
 * business-local date stamped at issue; all day counts use the organization
 * timezone.
 *
 * - invoice_due_soon (warning): due today … today + `due_soon_days`.
 * - invoice_overdue_unpaid (critical): past due, nothing paid.
 * - invoice_overdue_partial (critical): past due, partly paid.
 * - invoice_payment_followup_backlog (warning / critical): ≥ N open overdue
 *   follow-ups explicitly linked to the invoice, or one overdue ≥ M days.
 * Exactly one of the three payment types holds at a time, so a payment or a
 * passing due date closes one episode and opens the next (e.g. unpaid →
 * partial → resolved); a reversed payment can make a condition reappear as a
 * new episode. Episodes, ownership, notification and escalation are shared
 * engine services.
 */
final class InvoiceAttentionDetector
{
    private const CHUNK = 200;

    private const COLUMNS = ['id', 'organization_id', 'store_id', 'sales_order_id', 'customer_id', 'status', 'invoice_number',
        'invoice_date', 'due_date', 'currency_code', 'total_incl_tax', 'customer_name', 'customer_company'];

    public function __construct(
        private readonly FollowUpQuery $followUps,
        private readonly AttentionEpisodes $episodes,
        private readonly FinanceInvoiceReadModel $invoices,
    ) {}

    /** @return array{detected: int, resolved: int, failed: int} */
    public function scanOrganization(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $today = BusinessTime::today($organization);
        // Exclusive upper bound (day after the horizon): correct whether the driver keeps DATE values
        // as 'Y-m-d' (MySQL) or as cast-written 'Y-m-d 00:00:00' (SQLite), and index-friendly.
        $beforeDay = CarbonImmutable::parse($today, 'UTC')->addDays((int) config('attention.invoices.due_soon_days') + 1)->toDateString();
        $result = ['detected' => 0, 'resolved' => 0, 'failed' => 0];

        // Due on or before the due-soon horizon: invoice_org_status_due_idx (organization_id, status, due_date).
        Invoice::query()->where('organization_id', $organization->getKey())
            ->where('status', InvoiceStatus::Issued->value)
            ->whereNotNull('due_date')->where('due_date', '<', $beforeDay)
            ->select(self::COLUMNS)
            ->chunkById(self::CHUNK, function (Collection $invoices) use ($organization, $now, $today, &$result) {
                $summaries = $this->summaries($invoices);
                $conditions = [];
                foreach ($invoices as $invoice) {
                    $this->episodes->guard($result, function () use ($invoice, $summaries, $today, &$conditions) {
                        foreach ($this->paymentConditions($invoice, $summaries->get($invoice->getKey()), $today) as $condition) {
                            $conditions[] = $this->condition($invoice, ...$condition);
                        }
                    });
                }
                $result['detected'] += $this->episodes->apply($organization, $conditions, $now, $result);
            });

        $this->episodes->guard($result, function () use ($organization, $today, $now, &$result) {
            $result['detected'] += $this->detectBacklog($organization, $today, $now, $result);
        });

        if ($result['failed'] === 0) {
            $result['resolved'] = $this->episodes->closeUnconfirmed($organization, AttentionItem::SUBJECT_INVOICE, $now);
        }

        return $result;
    }

    /** The severity the item's condition has right now (NULL = no longer holds), same rules as the scan. */
    public function currentSeverity(Organization $organization, AttentionItem $item): ?AttentionSeverity
    {
        if ($item->active_key === null || $item->invoice_id === null) {
            return null;
        }
        $invoice = Invoice::query()->where('organization_id', $organization->getKey())->whereKey($item->invoice_id)->first(self::COLUMNS);
        if (! $invoice || $invoice->status !== InvoiceStatus::Issued) {
            return null;
        }
        $summary = $this->summaries(collect([$invoice]))->get($invoice->getKey());
        $today = BusinessTime::today($organization);

        if ($item->type === AttentionType::InvoicePaymentFollowUpBacklog) {
            $group = $this->isOutstanding($summary) ? $this->backlogGroups($organization, $today, [(int) $invoice->getKey()])->first() : null;

            return $group ? $this->backlogSeverity($group) : null;
        }
        foreach ($this->paymentConditions($invoice, $summary, $today) as [$type, $severity]) {
            if ($type === $item->type) {
                return $severity;
            }
        }

        return null;
    }

    /**
     * W4.4 transparent urgency within a severity: days left subtracted from
     * the window (due soon: sooner = higher), days overdue (unpaid / partial),
     * oldest overdue follow-up days × 100 + count (backlog).
     *
     * @param array<string, mixed> $facts
     */
    public static function urgency(AttentionType $type, array $facts): int
    {
        $days = max(0, (int) ($facts['days'] ?? 0));

        return match ($type) {
            AttentionType::InvoiceDueSoon => max(0, (int) config('attention.invoices.due_soon_days') - $days),
            AttentionType::InvoicePaymentFollowUpBacklog => $days * 100 + min(99, (int) ($facts['count'] ?? 0)),
            default => $days,
        };
    }

    /** @param array<string, mixed>|null $summary @return list<array{0: AttentionType, 1: AttentionSeverity, 2: array<string, mixed>}> */
    private function paymentConditions(Invoice $invoice, ?array $summary, string $today): array
    {
        if ($invoice->due_date === null || ! $this->isOutstanding($summary)) {
            return [];
        }
        $due = $invoice->due_date->format('Y-m-d');
        $days = fn (string $from, string $to) => (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), false);

        if ($due < $today) {
            $facts = ['date' => $due, 'days' => $days($due, $today)];
            if (Decimal::compare($summary['paid_amount'], '0') <= 0) {
                return [[AttentionType::InvoiceOverdueUnpaid, AttentionSeverity::Critical, $facts]];
            }

            return [[AttentionType::InvoiceOverduePartial, AttentionSeverity::Critical,
                [...$facts, 'outstanding' => (string) $summary['outstanding'], 'currency' => (string) $invoice->currency_code]]];
        }
        if ($days($today, $due) <= (int) config('attention.invoices.due_soon_days')) {
            return [[AttentionType::InvoiceDueSoon, AttentionSeverity::Warning, ['date' => $due, 'days' => $days($today, $due)]]];
        }

        return [];
    }

    /** @param array<string, int> $result */
    private function detectBacklog(Organization $organization, string $today, CarbonImmutable $now, array &$result): int
    {
        $detected = 0;
        foreach ($this->backlogGroups($organization, $today)->chunk(self::CHUNK) as $chunk) {
            $invoices = Invoice::query()->where('organization_id', $organization->getKey())->whereKey($chunk->pluck('invoice_id')->all())
                ->where('status', InvoiceStatus::Issued->value)->get(self::COLUMNS)->keyBy('id');
            $summaries = $this->summaries($invoices);
            $conditions = [];
            foreach ($chunk as $group) {
                $invoice = $invoices->get($group['invoice_id']);
                if (! $invoice || ! $this->isOutstanding($summaries->get($invoice->getKey()))) {
                    continue;
                }
                $conditions[] = $this->condition($invoice, AttentionType::InvoicePaymentFollowUpBacklog, $this->backlogSeverity($group),
                    ['count' => $group['count'], 'date' => $group['date'], 'days' => $group['days']]);
            }
            $detected += $this->episodes->apply($organization, $conditions, $now, $result);
        }

        return $detected;
    }

    /**
     * Invoice-linked overdue follow-up groups meeting the backlog threshold.
     *
     * @param list<int>|null $invoiceIds
     * @return Collection<int, array{invoice_id: int, count: int, date: string, days: int}>
     */
    private function backlogGroups(Organization $organization, string $today, ?array $invoiceIds = null): Collection
    {
        $config = config('attention.invoices');
        $overdue = FollowUp::query()->where('organization_id', $organization->getKey())->whereNotNull('invoice_id')
            ->when($invoiceIds !== null, fn ($query) => $query->whereIn('invoice_id', $invoiceIds));
        $this->followUps->applyBucket($overdue, $organization, 'overdue');

        return $overdue->groupBy('invoice_id')
            ->selectRaw('invoice_id, COUNT(*) as overdue_count, MIN(due_date) as oldest_due')
            ->orderBy('invoice_id')->get()
            ->map(fn ($row) => ['invoice_id' => (int) $row->invoice_id, 'count' => (int) $row->overdue_count,
                'date' => (string) $row->oldest_due, 'days' => (int) CarbonImmutable::parse((string) $row->oldest_due, 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC'), false)])
            ->filter(fn (array $group) => $group['count'] >= (int) $config['followup_backlog_count'] || $group['days'] >= (int) $config['followup_backlog_age_days'])
            ->values();
    }

    /** @param array{count: int, days: int} $group */
    private function backlogSeverity(array $group): AttentionSeverity
    {
        $config = config('attention.invoices');

        return $group['count'] >= (int) $config['followup_backlog_critical_count'] || $group['days'] >= (int) $config['followup_backlog_critical_age_days']
            ? AttentionSeverity::Critical : AttentionSeverity::Warning;
    }

    /** Canonical payment summaries keyed by invoice id (a fixed number of batched queries). */
    private function summaries(Collection $invoices): Collection
    {
        return $invoices->isEmpty() ? collect() : $this->invoices->withPaymentSummaries($invoices)->keyBy('id');
    }

    /** @param array<string, mixed>|null $summary */
    private function isOutstanding(?array $summary): bool
    {
        return $summary !== null && Decimal::compare($summary['outstanding'], '0') > 0;
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private function condition(Invoice $invoice, AttentionType $type, AttentionSeverity $severity, array $facts): array
    {
        [$title, $summary, $i18n] = $this->text($type, $invoice, $facts);

        return ['subject_id' => (int) $invoice->getKey(), 'store_id' => $invoice->store_id, 'customer_id' => $invoice->customer_id,
            'invoice_id' => (int) $invoice->getKey(), 'number' => $invoice->invoice_number, 'type' => $type, 'severity' => $severity,
            'facts' => $facts, 'title' => $title, 'summary' => $summary, 'i18n' => $i18n, 'urgency' => self::urgency($type, $facts)];
    }

    /** Stored French text + i18n keys. Amounts only in the partial summary (visible to invoice viewers only). @param array<string, mixed> $facts @return array{0: string, 1: string, 2: array<string, mixed>} */
    private function text(AttentionType $type, Invoice $invoice, array $facts): array
    {
        $number = $invoice->invoice_number ?? ('#'.$invoice->getKey());
        $date = CarbonImmutable::parse((string) $facts['date'], 'UTC')->format('d/m/Y');
        // Display only: Decimal rounds at 2 places, then the 4-place internal scale is cut (no floats).
        $amount = isset($facts['outstanding']) ? preg_replace('/(\.\d{2})\d*$/', '$1', Decimal::round($facts['outstanding'], 2)).' '.$facts['currency'] : '';
        $params = ['number' => $number, 'date' => $date, 'days' => (string) $facts['days'], 'count' => (string) ($facts['count'] ?? ''), 'amount' => $amount];
        [$key, $title, $summary] = match ($type) {
            AttentionType::InvoiceDueSoon => ['invoiceDueSoon', "Facture {$number} à échéance proche", "Échéance le {$date} — {$facts['days']} jour(s) restant(s), solde non réglé."],
            AttentionType::InvoiceOverdueUnpaid => ['invoiceOverdueUnpaid', "Facture {$number} en retard, non payée", "Échéance dépassée depuis le {$date} ({$facts['days']} j), aucun paiement."],
            AttentionType::InvoiceOverduePartial => ['invoiceOverduePartial', "Facture {$number} en retard, partiellement payée", "Échéance dépassée depuis le {$date} ({$facts['days']} j) — reste {$amount}."],
            AttentionType::InvoicePaymentFollowUpBacklog => ['invoiceBacklog', "Relances de paiement en retard — facture {$number}", "{$facts['count']} suivi(s) en retard, le plus ancien prévu le {$date} ({$facts['days']} j)."],
            default => throw new LogicException('Not an invoice attention type.'),
        };

        return [$title, $summary, ['title' => "attention.{$key}Title", 'message' => "attention.{$key}Summary", 'params' => $params]];
    }
}
