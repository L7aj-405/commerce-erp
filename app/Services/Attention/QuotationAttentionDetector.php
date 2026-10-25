<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * Episodes: a holding condition updates its active row (one per condition,
 * DB-unique active_key); a dismissed row stays dismissed while the episode
 * lasts; a snoozed row reopens once snoozed_until passes. Every active row not
 * re-confirmed by a complete scan closes (resolved; a dismissed row keeps its
 * status but its episode ends), so a later reappearance opens a new episode.
 */
final class QuotationAttentionDetector
{
    private const CHUNK = 200;

    public function __construct(private readonly FollowUpQuery $followUps) {}

    /** Scheduler entry: active organizations in chunks, one failing organization never stops the scan. @return array{organizations: int, failed: int} */
    public function scanAll(?CarbonImmutable $now = null): array
    {
        $result = ['organizations' => 0, 'failed' => 0];
        Organization::query()->where('status', 'active')->select(['id', 'name', 'status', 'settings'])
            ->chunkById(100, function ($organizations) use ($now, &$result) {
                foreach ($organizations as $organization) {
                    try {
                        $this->scanOrganization($organization, $now);
                        $result['organizations']++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

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
                    $lastSent = DocumentEmailDelivery::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('document_type', Quotation::class)->whereIn('document_id', $quotations->modelKeys())
                        ->where('status', DocumentEmailDelivery::STATUS_SENT)->whereNotNull('sent_at')
                        ->groupBy('document_id')->selectRaw('document_id, MAX(sent_at) as last_sent_at')
                        ->pluck('last_sent_at', 'document_id');
                    $conditions = [];
                    foreach ($quotations as $quotation) {
                        $this->guard($result, function () use ($organization, $quotation, $lastSent, $today, &$conditions) {
                            foreach ($this->documentConditions($organization, $quotation, $lastSent->get($quotation->getKey()), $today) as $condition) {
                                $conditions[] = [$quotation, ...$condition];
                            }
                        });
                    }
                    $result['detected'] += $this->apply($organization, $conditions, $now, $result);
                });
        }

        $this->guard($result, function () use ($organization, $today, $now, &$result) {
            $result['detected'] += $this->detectBacklog($organization, $today, $now, $result);
        });

        // Only a complete scan may close episodes (a failed row must not look "resolved").
        if ($result['failed'] === 0) {
            $result['resolved'] = $this->closeUnconfirmed($organization, $now);
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
        $config = config('attention.quotations');
        $overdue = FollowUp::query()->where('organization_id', $organization->getKey())->whereNotNull('quotation_id');
        $this->followUps->applyBucket($overdue, $organization, 'overdue');
        $groups = $overdue->groupBy('quotation_id')
            ->selectRaw('quotation_id, COUNT(*) as overdue_count, MIN(due_date) as oldest_due')
            ->orderBy('quotation_id')->get()
            ->map(fn ($row) => ['quotation_id' => (int) $row->quotation_id, 'count' => (int) $row->overdue_count,
                'date' => (string) $row->oldest_due, 'days' => (int) CarbonImmutable::parse((string) $row->oldest_due, 'UTC')->diffInDays(CarbonImmutable::parse($today, 'UTC'), false)])
            ->filter(fn (array $group) => $group['count'] >= (int) $config['followup_backlog_count'] || $group['days'] >= (int) $config['followup_backlog_age_days']);

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
                $critical = $group['count'] >= (int) $config['followup_backlog_critical_count'] || $group['days'] >= (int) $config['followup_backlog_critical_age_days'];
                $conditions[] = [$quotation, AttentionType::QuotationFollowUpBacklog, $critical ? AttentionSeverity::Critical : AttentionSeverity::Warning,
                    ['count' => $group['count'], 'date' => $group['date'], 'days' => $group['days']]];
            }
            $detected += $this->apply($organization, $conditions, $now, $result);
        }

        return $detected;
    }

    /**
     * Confirm a chunk of holding conditions. Unchanged active items are only
     * re-confirmed (one bulk `last_detected_at` update); new episodes, changed
     * facts and elapsed snoozes go through the row-locked upsert.
     *
     * @param list<array{0: Quotation, 1: AttentionType, 2: AttentionSeverity, 3: array<string, mixed>}> $conditions
     * @param array<string, int> $result
     */
    private function apply(Organization $organization, array $conditions, CarbonImmutable $now, array &$result): int
    {
        if ($conditions === []) {
            return 0;
        }
        $key = fn (AttentionType $type, Quotation $quotation) => $type->value.':'.AttentionItem::SUBJECT_QUOTATION.':'.$quotation->getKey();
        $existing = AttentionItem::query()->where('organization_id', $organization->getKey())
            ->whereIn('active_key', array_map(fn (array $condition) => $key($condition[1], $condition[0]), $conditions))
            ->get()->keyBy('active_key');

        $confirm = [];
        foreach ($conditions as [$quotation, $type, $severity, $facts]) {
            $item = $existing->get($key($type, $quotation));
            [$title, $summary] = $this->text($type, $quotation, $facts);
            $unchanged = $item !== null
                && $item->severity === $severity && $item->title === $title && $item->summary === $summary
                && (int) $item->store_id === (int) $quotation->store_id && $item->customer_id === $quotation->customer_id
                && ($item->metadata['facts'] ?? null) == $facts
                && ! ($item->status === AttentionStatus::Snoozed && $item->snoozed_until !== null && $item->snoozed_until->lessThanOrEqualTo($now));
            if ($unchanged) {
                $confirm[] = $item->getKey();
            } else {
                $this->guard($result, fn () => $this->upsert($organization, $quotation, $type, $severity, $facts, now: $now));
            }
        }
        if ($confirm !== []) {
            // Still-active rows only: an episode closed meanwhile is not revived (a new one opens next scan).
            DB::table('attention_items')->whereIn('id', $confirm)->whereNotNull('active_key')->update(['last_detected_at' => $now]);
        }

        return count($conditions);
    }

    /** @param array<string, mixed> $facts */
    private function upsert(Organization $organization, Quotation $quotation, AttentionType $type, AttentionSeverity $severity, array $facts, CarbonImmutable $now): void
    {
        $key = $type->value.':'.AttentionItem::SUBJECT_QUOTATION.':'.$quotation->getKey();
        [$title, $summary, $i18n] = $this->text($type, $quotation, $facts);

        DB::transaction(function () use ($organization, $quotation, $type, $severity, $facts, $now, $key, $title, $summary, $i18n) {
            $item = AttentionItem::query()->where('organization_id', $organization->getKey())->where('active_key', $key)->lockForUpdate()->first();
            if ($item === null) {
                $item = new AttentionItem;
                $item->organization_id = $organization->getKey();
                $item->type = $type;
                $item->subject_type = AttentionItem::SUBJECT_QUOTATION;
                $item->subject_id = $quotation->getKey();
                $item->episode = 1 + (int) AttentionItem::query()->where('organization_id', $organization->getKey())->where('type', $type->value)
                    ->where('subject_type', AttentionItem::SUBJECT_QUOTATION)->where('subject_id', $quotation->getKey())->max('episode');
                $item->active_key = $key;
                $item->status = AttentionStatus::Open;
                $item->detected_at = $now;
                $item->version = 1;
            } elseif ($item->status === AttentionStatus::Snoozed && $item->snoozed_until !== null && $item->snoozed_until->lessThanOrEqualTo($now)) {
                $item->status = AttentionStatus::Open;
                $item->snoozed_until = null;
                $item->version = (int) $item->version + 1;
            }

            $item->store_id = $quotation->store_id;
            $item->customer_id = $quotation->customer_id;
            $item->quotation_id = $quotation->getKey();
            $item->severity = $severity;
            $item->title = $title;
            $item->summary = $summary;
            // Snapshot for display only; follow-ups created from this item are kept.
            $item->metadata = ['quotation_number' => $quotation->quotation_number, 'facts' => $facts, 'i18n' => $i18n,
                'follow_up_ids' => $item->metadata['follow_up_ids'] ?? []];
            $item->last_detected_at = $now;

            try {
                DB::transaction(fn () => $item->save());
            } catch (UniqueConstraintViolationException) {
                // A concurrent detector opened this episode first; it will be refreshed next scan.
            }
        });
    }

    private function closeUnconfirmed(Organization $organization, CarbonImmutable $scanStart): int
    {
        $closed = 0;
        AttentionItem::query()->where('organization_id', $organization->getKey())
            ->whereNotNull('active_key')->where('last_detected_at', '<', $scanStart)
            ->select(['id'])->chunkById(self::CHUNK, function ($items) use ($scanStart, &$closed) {
                foreach ($items as $row) {
                    $closed += DB::transaction(function () use ($row, $scanStart) {
                        $item = AttentionItem::query()->whereKey($row->getKey())->lockForUpdate()->first();
                        if (! $item || $item->active_key === null || $item->last_detected_at->greaterThanOrEqualTo($scanStart)) {
                            return 0;
                        }
                        $item->active_key = null;
                        $item->resolved_at = $scanStart;
                        if (in_array($item->status, [AttentionStatus::Open, AttentionStatus::Snoozed], true)) {
                            // A dismissed episode keeps its status (the person's decision); its episode simply ends.
                            $item->status = AttentionStatus::Resolved;
                            $item->snoozed_until = null;
                        }
                        $item->version = (int) $item->version + 1;
                        $item->save();

                        return 1;
                    });
                }
            });

        return $closed;
    }

    /** @param array<string, mixed> $result */
    private function guard(array &$result, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            $result['failed']++;
            report($exception);
        }
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
        };
        $key = match ($type) {
            AttentionType::QuotationSentUndecided => 'sentUndecided',
            AttentionType::QuotationExpiringSoon => 'expiringSoon',
            AttentionType::QuotationExpiredUndecided => 'expiredUndecided',
            AttentionType::QuotationFollowUpBacklog => 'followupBacklog',
        };

        return [$title, $summary, ['title' => "attention.{$key}Title", 'message' => "attention.{$key}Summary", 'params' => $params]];
    }
}
