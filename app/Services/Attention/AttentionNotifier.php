<?php

namespace App\Services\Attention;

use App\Enums\AttentionSeverity;
use App\Enums\AttentionStatus;
use App\Enums\AttentionType;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\AttentionItem;
use App\Models\Organization;
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
 * Recipient (W4.3): the item's owner (AttentionOwnership — initially the
 * quotation issuer, else creator, else one fallback manager for critical
 * items), if still a valid owner, with Sales notifications enabled (checked
 * by the publisher). No broadcast. No owner / Sales disabled → processed
 * without delivery (notified_at stays NULL); the item stays in the Attention
 * Center. An owner who just became invalid is left for the ownership repair
 * (not processed). Reassigning never re-sends this notification.
 *
 * Before deciding, the condition is re-checked with the detector's own rules
 * (currentSeverity): an episode that is no longer critical is left untouched
 * for the next scan to update or resolve. A failing item rolls back alone and
 * is retried at the next scan (no hot loop).
 */
final class AttentionNotifier
{
    public function __construct(
        private readonly AttentionDetectors $detector,
        private readonly AttentionOwnership $ownership,
        private readonly FollowUpAccess $access,
        private readonly NotificationPublisher $publisher,
    ) {}

    /** @return array{sent: int, skipped: int, failed: int} */
    /** @param list<string> $exceptSubjects domains not yet baselined (W4.5.1) */
    public function notifyOrganization(Organization $organization, ?CarbonImmutable $now = null, array $exceptSubjects = []): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        AttentionItem::query()->where('organization_id', $organization->getKey())
            ->where('status', AttentionStatus::Open->value)->where('severity', AttentionSeverity::Critical->value)
            ->whereNull('notification_processed_at')->whereNotNull('active_key')
            ->when($exceptSubjects !== [], fn ($query) => $query->whereNotIn('subject_type', $exceptSubjects))
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

            $recipient = $item->assigned_user_id === null ? null : User::query()->find($item->assigned_user_id);
            if ($recipient !== null && ! $this->ownership->isValidOwner($organization, $item, (int) $recipient->getKey())) {
                return 'skipped'; // The ownership repair re-assigns it first.
            }
            $sent = $recipient ? $this->publish($organization, $recipient, $item) : 0;

            $item->notification_processed_at = $now;
            $item->notified_at = $sent > 0 ? $now : null;
            $item->save();

            return $sent > 0 ? 'sent' : 'skipped';
        });
    }

    private function publish(Organization $organization, User $recipient, AttentionItem $item): int
    {
        $params = $item->metadata['i18n']['params'] ?? [];
        $number = (string) ($params['number'] ?? $item->metadata['document_number'] ?? $item->metadata['quotation_number'] ?? '');
        $customer = $item->customer_id && $this->access->allows($recipient, $organization, 'customers.view')
            ? (string) $item->customer()->value('display_name') : '';
        $params = ['number' => $number, 'customer' => $customer, 'date' => (string) ($params['date'] ?? ''),
            'days' => (string) ($params['days'] ?? ''), 'count' => (string) ($params['count'] ?? '')];

        [$key, $title, $message] = match ($item->type) {
            AttentionType::QuotationFollowUpBacklog => ['Backlog', "Suivis en retard sur le devis {$number}", "{$params['count']} suivi(s) en retard, le plus ancien depuis {$params['days']} j."],
            AttentionType::InvoiceOverdueUnpaid => ['InvoiceOverdue', "Facture en retard : {$number}", "Échéance dépassée depuis le {$params['date']}, aucun paiement."],
            AttentionType::InvoiceOverduePartial => ['InvoicePartial', "Paiement partiel en retard : {$number}", "Échéance dépassée depuis le {$params['date']}, solde partiellement réglé."],
            AttentionType::InvoicePaymentFollowUpBacklog => ['InvoiceBacklog', "Relances en retard sur la facture {$number}", "{$params['count']} suivi(s) en retard, le plus ancien depuis {$params['days']} j."],
            AttentionType::SalesOrderUnpaidOverdue => ['OrderOverdue', "Commande impayée en retard : {$number}", "Échéance dépassée depuis le {$params['date']}, aucun paiement."],
            AttentionType::SalesOrderPartialOverdue => ['OrderPartial', "Paiement partiel en retard : {$number}", "Échéance dépassée depuis le {$params['date']}, solde partiellement réglé."],
            AttentionType::SalesOrderPaymentFollowUpBacklog => ['OrderBacklog', "Relances en retard sur la commande {$number}", "{$params['count']} suivi(s) en retard, le plus ancien depuis {$params['days']} j."],
            default => ['Expired', "Devis expiré sans décision : {$number}", "Validité dépassée depuis le {$params['date']}."],
        };
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
