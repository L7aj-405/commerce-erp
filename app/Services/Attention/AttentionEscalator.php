<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\Notifications\NotificationPublisher;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * W4.3 one opinionated escalation level — informational only.
 *
 * A CRITICAL item escalates once per episode when it is still active and
 * open (not snoozed into the future, not dismissed, not resolved) at least
 * ESCALATION_DAYS organization-local calendar days after it first became
 * critical, and nobody created a follow-up from it (that converts the
 * attention into concrete work; a later overdue follow-up is the backlog
 * attention's job). Claiming or reassigning does not stop escalation.
 *
 * Recipient — never a broadcast: if the owner is not a manager, the one
 * deterministic fallback manager of the store other than the owner
 * (AttentionOwnership::fallbackManager); if the owner is already a manager,
 * or the item is unassigned and no manager exists, the item is marked
 * escalated without any notification. Dedupe: escalated_at is set once under
 * a row lock with the notification insert, plus the publisher's per-user key
 * attention_item:{id}:escalation. Stale items (no longer critical by the
 * detector's own rules) are left for the next scan.
 */
final class AttentionEscalator
{
    public const ESCALATION_DAYS = 2;

    public function __construct(
        private readonly QuotationAttentionDetector $detector,
        private readonly AttentionOwnership $ownership,
        private readonly FollowUpAccess $access,
        private readonly NotificationPublisher $publisher,
    ) {}

    /** @return array{escalated: int, notified: int, failed: int} */
    public function escalateOrganization(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $result = ['escalated' => 0, 'notified' => 0, 'failed' => 0];
        // Started being critical before the local day that is ESCALATION_DAYS before today.
        $cutoff = BusinessTime::localToUtc($organization, CarbonImmutable::parse(BusinessTime::today($organization), 'UTC')->subDays(self::ESCALATION_DAYS - 1)->toDateString());

        AttentionItem::query()->where('organization_id', $organization->getKey())
            ->where('status', AttentionStatus::Open->value)->where('severity', AttentionSeverity::Critical->value)
            ->whereNotNull('active_key')->whereNull('escalated_at')
            ->whereNotNull('critical_since')->where('critical_since', '<', $cutoff)
            ->select(['id'])->chunkById(100, function ($items) use ($organization, $now, $cutoff, &$result) {
                foreach ($items as $row) {
                    try {
                        $outcome = $this->escalate($organization, (int) $row->getKey(), $now, $cutoff);
                        if ($outcome !== null) {
                            $result['escalated']++;
                            $result['notified'] += $outcome ? 1 : 0;
                        }
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

    /** @return bool|null null = not escalated; true = escalated and a manager notified; false = escalated silently */
    private function escalate(Organization $organization, int $id, CarbonImmutable $now, CarbonImmutable $cutoff): ?bool
    {
        return DB::transaction(function () use ($organization, $id, $now, $cutoff) {
            $item = AttentionItem::query()->where('organization_id', $organization->getKey())->whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->active_key === null || $item->escalated_at !== null || $item->status !== AttentionStatus::Open
                || $item->severity !== AttentionSeverity::Critical || $item->critical_since === null || ! $item->critical_since->lessThan($cutoff)) {
                return null;
            }
            if (($item->metadata['follow_up_ids'] ?? []) !== []) {
                return null; // Handled: converted into a follow-up.
            }
            if ($this->detector->currentSeverity($organization, $item) !== AttentionSeverity::Critical) {
                return null; // Stale until the next scan.
            }

            $owner = $item->assigned_user_id === null ? null : (int) $item->assigned_user_id;
            $ownerIsManager = $owner !== null && $this->access->allows(User::query()->findOrFail($owner), $organization, 'follow_ups.manage_all');
            $managerId = $ownerIsManager ? null : $this->ownership->fallbackManager($organization, $item->store_id, $owner);

            $sent = $managerId === null ? 0 : $this->publish($organization, User::query()->findOrFail($managerId), $item);
            $item->escalated_at = $now;
            $item->save();

            return $sent > 0;
        });
    }

    private function publish(Organization $organization, User $manager, AttentionItem $item): int
    {
        $params = $item->metadata['i18n']['params'] ?? [];
        $number = (string) ($params['number'] ?? $item->metadata['quotation_number'] ?? '');
        $customer = $item->customer_id && $this->access->allows($manager, $organization, 'customers.view')
            ? (string) $item->customer()->value('display_name') : '';
        $owner = $item->assigned_user_id ? (string) User::query()->whereKey($item->assigned_user_id)->value('name') : '';
        $params = ['number' => $number, 'customer' => $customer, 'owner' => $owner];
        $context = implode(' — ', array_filter([$customer, $owner !== '' ? "responsable : {$owner}" : 'non assignée']));
        $key = $owner !== '' ? 'Owner' : 'Unassigned';

        return $this->publisher->publishToUser($manager, $organization, NotificationCategory::Sales, NotificationSeverity::Critical,
            "Attention critique non traitée : devis {$number}", $context,
            '/work/attention?view=all&focus='.$item->getKey(),
            'attention_item', $item->getKey(), 'escalation',
            [
                'attention_item_id' => (int) $item->getKey(),
                'i18n' => ['title' => 'attention.escalationTitle', 'message' => 'attention.escalationMessage'.$key.($customer !== '' ? 'Context' : ''), 'params' => $params],
            ]);
    }
}
