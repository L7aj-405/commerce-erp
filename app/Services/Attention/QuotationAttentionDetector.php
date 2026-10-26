<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionType;
use App\Enums\QuotationStatus;
use App\Models\AttentionItem;
use App\Models\DocumentEmailDelivery;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Quotation;
use App\Services\FollowUps\FollowUpQuery;
use App\Services\FollowUps\FollowUpRecurringGenerator;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * W4.1 canonical quotation attention rules — detection and resolution only.
 * It never modifies a quotation, sends anything or creates follow-ups.
 *
 * "Current & undecided" = status issued or expired: issuing a revision marks
 * the predecessor superseded, and accepted / rejected / converted are
 * decisions, so these statuses are exactly the live, undecided versions.
 * All day counts use the organization business timezone (BusinessTime).
 *
 * - quotation_sent_undecided (warning): last successful email delivery
 *   (document_email_deliveries, status sent — failures and PDF downloads do
 *   not count) at least `sent_undecided_days` local days ago.
 * - quotation_expiring_soon (warning): valid_until between today and today +
 *   `expiring_soon_days` (inclusive), not yet past.
 * - quotation_expired_undecided (critical): valid_until before today (TIME-1:
 *   valid through the whole local day) — independent of the lazy expired status.
 * - quotation_followup_backlog (warning / critical): quotation still
 *   actionable (Draft or isCurrentEligible, as for W3 rules) with at least
 *   `followup_backlog_count` open overdue linked follow-ups, or one overdue
 *   for at least `followup_backlog_age_days` local days (Work Center overdue
 *   bucket; completed, cancelled, future and customer-only work never count).
 *
 * Episodes, ownership, notification and escalation are shared engine
 * services (AttentionEpisodes, AttentionOwnership, AttentionNotifier,
 * AttentionEscalator); this class only describes quotation conditions.
 */
final class QuotationAttentionDetector
{
    private const CHUNK = 200;

    public function __construct(
        private readonly FollowUpQuery $followUps,
        private readonly AttentionEpisodes $episodes,
    ) {}

    /** @return array{detected: int, resolved: int, failed: int} */
    public function scanOrganization(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $today = BusinessTime::today($organization);
        $result = ['detected' => 0, 'resolved' => 0, 'failed' => 0];

        // One status at a time: an ordered range on quotation_org_status_idx (organization_id, status, id).
        foreach ([QuotationStatus::Issued, QuotationStatus::Expired] as $status) {
            Quotation::query()->where('organization_id', $organization->getKey())
                ->where('status', $status->value)
                ->select(['id', 'organization_id', 'store_id', 'customer_id', 'status', 'valid_until', 'quotation_number'])
                ->chunkById(self::CHUNK, function (Collection $quotations) use ($organization, $now, $today, &$result) {
                    $lastSent = $this->lastSent($organization, $quotations->modelKeys());
                    $conditions = [];
                    foreach ($quotations as $quotation) {
                        $this->episodes->guard($result, function () use ($organization, $quotation, $lastSent, $today, &$conditions) {
                            foreach ($this->documentConditions($organization, $quotation, $lastSent->get($quotation->getKey()), $today) as $condition) {
                                $conditions[] = $this->condition($quotation, ...$condition);
                            }
                        });
                    }
                    $result['detected'] += $this->episodes->apply($organization, $conditions, $now, $result);
                });
        }

        $this->episodes->guard($result, function () use ($organization, $today, $now, &$result) {
            $result['detected'] += $this->detectBacklog($organization, $today, $now, $result);
        });

        // Only a complete scan may close episodes (a failed row must not look "resolved").
        if ($result['failed'] === 0) {
            $result['resolved'] = $this->episodes->closeUnconfirmed($organization, AttentionItem::SUBJECT_QUOTATION, $now);
        }

        return $result;
    }

    /** @return list<array{0: AttentionType, 1: AttentionSeverity, 2: array<string, mixed>}> */
    private function documentConditions(Organization $organization, Quotation $quotation, ?string $lastSentAt, string $today): array
    {
        $config = config('attention.quotations');
        $conditions = [];
        $days = fn (string $from, string $to) => (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), false);

        if ($lastSentAt !== null) {
            $sentOn = BusinessTime::toLocal($organization, CarbonImmutable::parse($lastSentAt, 'UTC'))->toDateString();
            if ($days($sentOn, $today) >= (int) $config['sent_undecided_days']) {
                $conditions[] = [AttentionType::QuotationSentUndecided, AttentionSeverity::Warning, ['date' => $sentOn, 'days' => $days($sentOn, $today)]];
            }
        }

        $validUntil = $quotation->valid_until?->format('Y-m-d');
        if ($validUntil !== null) {
            if ($validUntil < $today) {
                $conditions[] = [AttentionType::QuotationExpiredUndecided, AttentionSeverity::Critical, ['date' => $validUntil, 'days' => $days($validUntil, $today)]];
            } elseif ($days($today, $validUntil) <= (int) $config['expiring_soon_days']) {
                $conditions[] = [AttentionType::QuotationExpiringSoon, AttentionSeverity::Warning, ['date' => $validUntil, 'days' => $days($today, $validUntil)]];
            }
        }

        return $conditions;
    }

    /** @param array<string, int> $result */
    private function detectBacklog(Organization $organization, string $today, CarbonImmutable $now, array &$result): int
    {
        $groups = $this->backlogGroups($organization, $today);

        $detected = 0;
        foreach ($groups->chunk(self::CHUNK) as $chunk) {
            $quotations = Quotation::query()->where('organization_id', $organization->getKey())->whereKey($chunk->pluck('quotation_id')->all())
                ->get(['id', 'organization_id', 'store_id', 'customer_id', 'status', 'valid_until', 'quotation_number'])->keyBy('id');
            $conditions = [];
            foreach ($chunk as $group) {
                $quotation = $quotations->get($group['quotation_id']);
                if (! $quotation || ! FollowUpRecurringGenerator::quotationIsActionable($quotation->status)) {
                    continue;
                }
                $conditions[] = $this->condition($quotation, AttentionType::QuotationFollowUpBacklog, $this->backlogSeverity($group),
                    ['count' => $group['count'], 'date' => $group['date'], 'days' => $group['days']]);
            }
            $detected += $this->episodes->apply($organization, $conditions, $now, $result);
        }

        return $detected;
    }

    /**
     * W4.2 canonical pre-delivery check: the severity the item's condition has
     * right now (NULL = no longer holds), using exactly the scan's rules for
     * that one quotation. Lets the notifier avoid acting on a stale episode
     * before the next scan resolves it, without a second copy of the rules.
     */
    public function currentSeverity(Organization $organization, AttentionItem $item): ?AttentionSeverity
    {
        if ($item->active_key === null || $item->quotation_id === null) {
            return null;
        }
        $quotation = Quotation::query()->where('organization_id', $organization->getKey())->whereKey($item->quotation_id)
            ->first(['id', 'organization_id', 'store_id', 'customer_id', 'status', 'valid_until', 'quotation_number']);
        if (! $quotation) {
            return null;
        }
        $today = BusinessTime::today($organization);

        if ($item->type === AttentionType::QuotationFollowUpBacklog) {
            $group = FollowUpRecurringGenerator::quotationIsActionable($quotation->status)
                ? $this->backlogGroups($organization, $today, [(int) $quotation->getKey()])->first() : null;

            return $group ? $this->backlogSeverity($group) : null;
        }
        if (! in_array($quotation->status, [QuotationStatus::Issued, QuotationStatus::Expired], true)) {
            return null;
        }
        foreach ($this->documentConditions($organization, $quotation, $this->lastSent($organization, [(int) $quotation->getKey()])->get($quotation->getKey()), $today) as [$type, $severity]) {
            if ($type === $item->type) {
                return $severity;
            }
        }

        return null;
    }

    /** Last successful email send per quotation. @param list<int> $quotationIds */
    private function lastSent(Organization $organization, array $quotationIds): Collection
    {
        return DocumentEmailDelivery::query()
            ->where('organization_id', $organization->getKey())
            ->where('document_type', Quotation::class)->whereIn('document_id', $quotationIds)
            ->where('status', DocumentEmailDelivery::STATUS_SENT)->whereNotNull('sent_at')
            ->groupBy('document_id')->selectRaw('document_id, MAX(sent_at) as last_sent_at')
            ->pluck('last_sent_at', 'document_id');
    }

    /**
     * Quotation-linked overdue follow-up groups meeting the backlog threshold.
     *
     * @param list<int>|null $quotationIds
     * @return Collection<int, array{quotation_id: int, count: int, date: string, days: int}>
     */
    private function backlogGroups(Organization $organization, string $today, ?array $quotationIds = null): Collection
    {
        $config = config('attention.quotations');
        $overdue = FollowUp::query()->where('organization_id', $organization->getKey())->whereNotNull('quotation_id')
            ->when($quotationIds !== null, fn ($query) => $query->whereIn('quotation_id', $quotationIds));
        $this->followUps->applyBucket($overdue, $organization, 'overdue');

        return $overdue->groupBy('quotation_id')
            ->selectRaw('quotation_id, COUNT(*) as overdue_count, MIN(due_date) as oldest_due')
            ->orderBy('quotation_id')->get()
            ->map(fn ($row) => ['quotation_id' => (int) $row->quotation_id, 'count' => (int) $row->overdue_count,
                'date' => (string) $row->oldest_due, 'days' => (int) CarbonImmutable::parse((string) $row->oldest_due, 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC'), false)])
            ->filter(fn (array $group) => $group['count'] >= (int) $config['followup_backlog_count'] || $group['days'] >= (int) $config['followup_backlog_age_days'])
            ->values();
    }

    /** @param array{count: int, days: int} $group */
    private function backlogSeverity(array $group): AttentionSeverity
    {
        $config = config('attention.quotations');

        return $group['count'] >= (int) $config['followup_backlog_critical_count'] || $group['days'] >= (int) $config['followup_backlog_critical_age_days']
            ? AttentionSeverity::Critical : AttentionSeverity::Warning;
    }

    /**
     * W4.2 transparent urgency within a severity (higher = more urgent):
     * days since the last send (sent), days left subtracted from the window
     * (expiring: sooner = higher), days past validity (expired), days the
     * oldest follow-up is overdue × 100 + overdue count (backlog).
     *
     * @param array<string, mixed> $facts
     */
    public static function urgency(AttentionType $type, array $facts): int
    {
        $days = max(0, (int) ($facts['days'] ?? 0));

        return match ($type) {
            AttentionType::QuotationExpiringSoon => max(0, (int) config('attention.quotations.expiring_soon_days') - $days),
            AttentionType::QuotationFollowUpBacklog => $days * 100 + min(99, (int) ($facts['count'] ?? 0)),
            default => $days,
        };
    }

    /** Describe one holding quotation condition for AttentionEpisodes. @param array<string, mixed> $facts @return array<string, mixed> */
    private function condition(Quotation $quotation, AttentionType $type, AttentionSeverity $severity, array $facts): array
    {
        [$title, $summary, $i18n] = $this->text($type, $quotation, $facts);

        return ['subject_id' => (int) $quotation->getKey(), 'store_id' => $quotation->store_id, 'customer_id' => $quotation->customer_id,
            'quotation_id' => (int) $quotation->getKey(), 'number' => $quotation->quotation_number, 'type' => $type, 'severity' => $severity,
            'facts' => $facts, 'title' => $title, 'summary' => $summary, 'i18n' => $i18n, 'urgency' => self::urgency($type, $facts)];
    }

    /** Stored French text (existing convention) + i18n keys for the viewer's language. @param array<string, mixed> $facts @return array{0: string, 1: string, 2: array<string, mixed>} */
    private function text(AttentionType $type, Quotation $quotation, array $facts): array
    {
        $number = $quotation->quotation_number ?? ('#'.$quotation->getKey());
        $date = CarbonImmutable::parse((string) $facts['date'], 'UTC')->format('d/m/Y');
        $params = ['number' => $number, 'date' => $date, 'days' => (string) $facts['days'], 'count' => (string) ($facts['count'] ?? '')];
        [$title, $summary] = match ($type) {
            AttentionType::QuotationSentUndecided => ["Devis {$number} envoyé sans réponse", "Dernier envoi le {$date}, il y a {$facts['days']} jours, toujours sans décision."],
            AttentionType::QuotationExpiringSoon => ["Devis {$number} bientôt expiré", "Valable jusqu’au {$date} — {$facts['days']} jour(s) restant(s), sans décision."],
            AttentionType::QuotationExpiredUndecided => ["Devis {$number} expiré sans décision", "Validité dépassée depuis le {$date} ({$facts['days']} j), sans décision du client."],
            AttentionType::QuotationFollowUpBacklog => ["Relances en retard — devis {$number}", "{$facts['count']} suivi(s) en retard, le plus ancien prévu le {$date} ({$facts['days']} j)."],
            default => throw new LogicException('Not a quotation attention type.'),
        };
        $key = match ($type) {
            AttentionType::QuotationSentUndecided => 'sentUndecided',
            AttentionType::QuotationExpiringSoon => 'expiringSoon',
            AttentionType::QuotationExpiredUndecided => 'expiredUndecided',
            AttentionType::QuotationFollowUpBacklog => 'followupBacklog',
            default => throw new LogicException('Not a quotation attention type.'),
        };

        return [$title, $summary, ['title' => "attention.{$key}Title", 'message' => "attention.{$key}Summary", 'params' => $params]];
    }
}
