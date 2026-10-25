<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\AttentionType;
use App\Models\AttentionItem;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Shared attention episode persistence (W4.1 rules, used by every domain
 * detector since W4.4). Domain detectors only decide *which* conditions hold
 * and describe them; this class owns rows, episodes and resolution.
 *
 * A condition is an array:
 *   subject_id, store_id, customer_id, quotation_id?, invoice_id?, number,
 *   type (AttentionType), severity, facts, title, summary, i18n, urgency.
 *
 * - One active row per condition (DB-unique active_key
 *   `{type}:{subject_type}:{subject_id}`); a holding condition refreshes it.
 * - Unchanged active rows are re-confirmed in bulk; new episodes, changed
 *   facts and elapsed snoozes go through a row-locked upsert.
 * - A dismissed row stays dismissed while its episode lasts; a snoozed row
 *   reopens once snoozed_until passes.
 * - After a complete domain scan, every active row of that subject type not
 *   re-confirmed closes (resolved; a dismissed row keeps its status but its
 *   episode ends), so a later reappearance opens a new episode.
 * - critical_since (W4.3 escalation clock) is set the first time an episode
 *   is critical and never reset within the episode.
 */
final class AttentionEpisodes
{
    private const CHUNK = 200;

    /** @param list<array<string, mixed>> $conditions @param array<string, int> $result */
    public function apply(Organization $organization, array $conditions, CarbonImmutable $now, array &$result): int
    {
        if ($conditions === []) {
            return 0;
        }
        $existing = AttentionItem::query()->where('organization_id', $organization->getKey())
            ->whereIn('active_key', array_map(fn (array $condition) => self::key($condition), $conditions))
            ->get()->keyBy('active_key');

        $confirm = [];
        foreach ($conditions as $condition) {
            $item = $existing->get(self::key($condition));
            $unchanged = $item !== null
                && $item->severity === $condition['severity'] && $item->title === $condition['title'] && $item->summary === $condition['summary']
                && (int) $item->store_id === (int) $condition['store_id'] && $item->customer_id === $condition['customer_id']
                && ($item->metadata['facts'] ?? null) == $condition['facts'] && (int) $item->urgency === (int) $condition['urgency']
                && ! ($item->status === AttentionStatus::Snoozed && $item->snoozed_until !== null && $item->snoozed_until->lessThanOrEqualTo($now));
            if ($unchanged) {
                $confirm[] = $item->getKey();
            } else {
                $this->guard($result, fn () => $this->upsert($organization, $condition, $now));
            }
        }
        if ($confirm !== []) {
            // Still-active rows only: an episode closed meanwhile is not revived (a new one opens next scan).
            DB::table('attention_items')->whereIn('id', $confirm)->whereNotNull('active_key')->update(['last_detected_at' => $now]);
        }

        return count($conditions);
    }

    /** Close every active row of one subject type that this (complete) scan did not re-confirm. */
    public function closeUnconfirmed(Organization $organization, string $subjectType, CarbonImmutable $scanStart): int
    {
        $closed = 0;
        AttentionItem::query()->where('organization_id', $organization->getKey())->where('subject_type', $subjectType)
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

    /** @param array<string, int> $result */
    public function guard(array &$result, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            $result['failed']++;
            report($exception);
        }
    }

    /** @param array<string, mixed> $condition */
    public static function key(array $condition): string
    {
        /** @var AttentionType $type */
        $type = $condition['type'];

        return $type->value.':'.$type->subjectType().':'.$condition['subject_id'];
    }

    /** @param array<string, mixed> $condition */
    private function upsert(Organization $organization, array $condition, CarbonImmutable $now): void
    {
        /** @var AttentionType $type */
        $type = $condition['type'];
        /** @var AttentionSeverity $severity */
        $severity = $condition['severity'];
        $key = self::key($condition);

        DB::transaction(function () use ($organization, $condition, $type, $severity, $now, $key) {
            $item = AttentionItem::query()->where('organization_id', $organization->getKey())->where('active_key', $key)->lockForUpdate()->first();
            if ($item === null) {
                $item = new AttentionItem;
                $item->organization_id = $organization->getKey();
                $item->type = $type;
                $item->subject_type = $type->subjectType();
                $item->subject_id = $condition['subject_id'];
                $item->episode = 1 + (int) AttentionItem::query()->where('organization_id', $organization->getKey())->where('type', $type->value)
                    ->where('subject_type', $type->subjectType())->where('subject_id', $condition['subject_id'])->max('episode');
                $item->active_key = $key;
                $item->status = AttentionStatus::Open;
                $item->detected_at = $now;
                $item->version = 1;
            } elseif ($item->status === AttentionStatus::Snoozed && $item->snoozed_until !== null && $item->snoozed_until->lessThanOrEqualTo($now)) {
                $item->status = AttentionStatus::Open;
                $item->snoozed_until = null;
                $item->version = (int) $item->version + 1;
            }

            $item->store_id = $condition['store_id'];
            $item->customer_id = $condition['customer_id'];
            $item->quotation_id = $condition['quotation_id'] ?? null;
            $item->invoice_id = $condition['invoice_id'] ?? null;
            $item->severity = $severity;
            $item->urgency = (int) $condition['urgency'];
            if ($severity === AttentionSeverity::Critical && $item->critical_since === null) {
                $item->critical_since = $now;
            }
            $item->title = $condition['title'];
            $item->summary = $condition['summary'];
            // Snapshot for display only; follow-ups created from this item are kept.
            $item->metadata = ['document_number' => $condition['number'], 'facts' => $condition['facts'], 'i18n' => $condition['i18n'],
                'follow_up_ids' => $item->metadata['follow_up_ids'] ?? []];
            $item->last_detected_at = $now;

            try {
                DB::transaction(fn () => $item->save());
            } catch (UniqueConstraintViolationException) {
                // A concurrent detector opened this episode first; it will be refreshed next scan.
            }
        });
    }
}
