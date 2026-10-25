<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Models\AttentionItem;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * W4.5.1 per-organization rollout baseline for attention domains whose
 * conditions exist historically (Sales Order receivables). Rollout safety,
 * not a tenant-facing concept.
 *
 * Until a domain is baselined for an organization, its items are detected,
 * owned and visible as usual, but AttentionScanner keeps the notifier and
 * escalator away from that domain. The first scan with zero failures then,
 * in one transaction, records the marker and flags every active critical
 * item of the domain as historical: notification processed without delivery
 * (notified_at stays NULL) and escalation_exempt. One bulk update, no audit,
 * no user notification. A failed scan records nothing and is retried next
 * run. Episodes opening after the marker (state changes, reversals, new
 * orders) are new rows and behave normally; a new organization baselines
 * with zero items on its first scan.
 *
 * Concurrency: the marker is UNIQUE per (organization, subject); the bulk
 * flag only runs in the run that owns the marker (established at or after
 * that run's scan start), so an old marker can never suppress new episodes.
 */
final class AttentionBaselines
{
    /** Domains that need a rollout baseline. */
    public const SUBJECTS = [AttentionItem::SUBJECT_SALES_ORDER];

    public function isPending(Organization $organization, string $subjectType): bool
    {
        return in_array($subjectType, self::SUBJECTS, true)
            && ! DB::table('attention_baselines')->where('organization_id', $organization->getKey())->where('subject_type', $subjectType)->exists();
    }

    /** Record the baseline after a complete scan that started at `$scanStart`; returns the number of historical items flagged. */
    public function establish(Organization $organization, string $subjectType, CarbonImmutable $scanStart): int
    {
        return DB::transaction(function () use ($organization, $subjectType, $scanStart) {
            $now = CarbonImmutable::now('UTC');
            DB::table('attention_baselines')->insertOrIgnore([
                'organization_id' => $organization->getKey(), 'subject_type' => $subjectType,
                'established_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $established = DB::table('attention_baselines')->where('organization_id', $organization->getKey())
                ->where('subject_type', $subjectType)->lockForUpdate()->value('established_at');
            if ($established === null || CarbonImmutable::parse((string) $established, 'UTC')->lessThan($scanStart->startOfSecond())) {
                return 0; // Established by an earlier run: today's episodes are not historical.
            }

            return AttentionItem::query()->where('organization_id', $organization->getKey())->where('subject_type', $subjectType)
                ->whereNotNull('active_key')->where('severity', AttentionSeverity::Critical->value)
                ->where('escalation_exempt', false)
                ->toBase()->update([
                    'notification_processed_at' => DB::raw('COALESCE(notification_processed_at, '.DB::getPdo()->quote($now->toDateTimeString()).')'),
                    'escalation_exempt' => true,
                ]);
        });
    }
}
