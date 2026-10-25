<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\AttentionType;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\AttentionItem;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\Notifications\NotificationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * W4.2 critical attention notifications — informational only; never acts on
 * quotations, customers or work.
 *
 * Eligible: active episode, status open, severity critical, not yet
 * processed. Each episode is processed at most once (row lock + re-check, the
 * decision committed with the notification insert; NotificationPublisher's
 * per-user dedupe on `attention_item:{id}:critical` as second layer), so:
 * repeated or overlapping runs never notify twice; critical → warning →
 * critical in one episode notifies once; a snoozed / dismissed / resolved
 * episode is not eligible (a snooze that ends while still critical becomes
 * eligible only if it was never processed); a new episode is a new row and
 * may notify again. Warnings never notify.
 *
 * Recipient (conservative, no broadcast, no manager fan-out): the
 * quotation's issuer, else its creator — the first who can still see the
 * item (active membership, quotations.view, store membership) and has Sales
 * notifications enabled (checked by the publisher). Nobody eligible / Sales
 * disabled → processed without delivery (notified_at stays NULL); the item
 * stays in the Attention Center.
 *
 * Before deciding, the condition is re-checked with the detector's own rules
 * (currentSeverity): an episode that is no longer critical is left untouched
 * for the next scan to update or resolve. A failing item rolls back alone and
 * is retried at the next scan (no hot loop).
 */
final class AttentionNotifier
{
    public function __construct(
        private readonly QuotationAttentionDetector $detector,
        private readonly AttentionQuery $attention,
        private readonly FollowUpAccess $access,
        private readonly NotificationPublisher $publisher,
    ) {}

    /** @return array{sent: int, skipped: int, failed: int} */
    public function notifyOrganization(Organization $organization, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        AttentionItem::query()->where('organization_id', $organization->getKey())
            ->where('status', AttentionStatus::Open->value)->where('severity', AttentionSeverity::Critical->value)
            ->whereNull('notification_processed_at')->whereNotNull('active_key')
            ->select(['id'])->chunkById(100, function ($items) use ($organization, $now, &$result) {
                foreach ($items as $row) {
                    try {
                        $result[$this->deliver($organization, (int) $row->getKey(), $now)]++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

    /** @return 'sent'|'skipped' */
    private function deliver(Organization $organization, int $id, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($organization, $id, $now) {
            $item = AttentionItem::query()->where('organization_id', $organization->getKey())->whereKey($id)->lockForUpdate()->first();
            if (! $item || $item->notification_processed_at !== null || $item->active_key === null
                || $item->status !== AttentionStatus::Open || $item->severity !== AttentionSeverity::Critical) {
                return 'skipped';
            }
            if ($this->detector->currentSeverity($organization, $item) !== AttentionSeverity::Critical) {
                return 'skipped'; // Stale until the next scan; no decision recorded.
            }

            $recipient = $this->recipient($organization, $item);
            $sent = $recipient ? $this->publish($organization, $recipient, $item) : 0;

            $item->notification_processed_at = $now;
            $item->notified_at = $sent > 0 ? $now : null;
            $item->save();

            return $sent > 0 ? 'sent' : 'skipped';
        });
    }

    private function recipient(Organization $organization, AttentionItem $item): ?User
    {
        $quotation = Quotation::query()->where('organization_id', $organization->getKey())->whereKey($item->quotation_id)
            ->first(['id', 'organization_id', 'issued_by_user_id', 'created_by_user_id']);
        foreach (array_unique(array_filter([$quotation?->issued_by_user_id, $quotation?->created_by_user_id])) as $userId) {
            $user = User::query()->find($userId);
            // Active membership is re-checked by the publisher; visibility here.
            if ($user && $this->attention->canView($user, $item)) {
                return $user;
            }
        }

        return null;
    }

    private function publish(Organization $organization, User $recipient, AttentionItem $item): int
    {
        $params = $item->metadata['i18n']['params'] ?? [];
        $number = (string) ($params['number'] ?? $item->metadata['quotation_number'] ?? '');
        $customer = $item->customer_id && $this->access->allows($recipient, $organization, 'customers.view')
            ? (string) $item->customer()->value('display_name') : '';
        $params = ['number' => $number, 'customer' => $customer, 'date' => (string) ($params['date'] ?? ''),
            'days' => (string) ($params['days'] ?? ''), 'count' => (string) ($params['count'] ?? '')];

        [$key, $title, $message] = $item->type === AttentionType::QuotationFollowUpBacklog
            ? ['Backlog', "Suivis en retard sur le devis {$number}", "{$params['count']} suivi(s) en retard, le plus ancien depuis {$params['days']} j."]
            : ['Expired', "Devis expiré sans décision : {$number}", "Validité dépassée depuis le {$params['date']}."];
        $context = $customer !== '' ? 'Context' : '';

        return $this->publisher->publishToUser($recipient, $organization, NotificationCategory::Sales, NotificationSeverity::Critical,
            $title, ($customer !== '' ? $customer.' — ' : '').$message,
            '/work/attention?view=all&focus='.$item->getKey(),
            'attention_item', $item->getKey(), 'critical',
            [
                'attention_item_id' => (int) $item->getKey(),
                'i18n' => ['title' => "attention.notify{$key}Title", 'message' => "attention.notify{$key}Message{$context}", 'params' => $params],
            ]);
    }
}
