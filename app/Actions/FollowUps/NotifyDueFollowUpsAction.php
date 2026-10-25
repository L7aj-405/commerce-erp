<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\Notifications\NotificationPublisher;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * W2: publish due-reminder notifications for follow-ups whose pending
 * occurrence (`remind_at`, see FollowUpReminderSchedule) has arrived.
 *
 * Exactly-once per occurrence, with any number of overlapping runs/workers:
 * each occurrence is claimed under a row lock (re-checking `remind_at`) and
 * the notification insert, `remind_at = NULL` and `notified_at` commit in one
 * transaction. NotificationPublisher's per-user unique dedupe key
 * (`event_type = due:{instant}:v{version}`) is the second line of defence; a
 * rescheduled occurrence has a new instant/version, so it notifies again.
 *
 * Only the current assignee is notified (Unassigned work: nobody in W2), and
 * only while they can still see the follow-up. Claiming consumes the
 * occurrence whether or not a notification was delivered (unassigned,
 * Sales notifications disabled, access lost), so opted-out work is never
 * reprocessed every run; `notified_at` is set only when one was published.
 * Notes are never included. A failing row rolls back alone (stays pending,
 * retried next run) and is reported without stopping the batch. No audit
 * event per reminder: user_notifications is the delivery history.
 */
class NotifyDueFollowUpsAction
{
    public const EVENT_PREFIX = 'due:';

    private const CHUNK = 200;

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly FollowUpAccess $access,
    ) {}

    /** @return array{sent: int, skipped: int, failed: int} */
    public function execute(?CarbonImmutable $now = null, int $limit = 2000): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $processed = 0;

        FollowUp::query()->whereNotNull('remind_at')->where('remind_at', '<=', $now)
            ->select(['id', 'organization_id', 'assigned_user_id', 'customer_id', 'quotation_id'])
            ->chunkById(self::CHUNK, function (Collection $candidates) use ($now, $limit, &$result, &$processed) {
                // One query per relation per chunk (no per-row N+1); rows are re-read under lock below.
                $organizations = Organization::query()->whereKey($candidates->pluck('organization_id')->unique()->all())->get()->keyBy('id');
                $users = User::query()->whereKey($candidates->pluck('assigned_user_id')->filter()->unique()->all())->get()->keyBy('id');
                $customers = Customer::query()->whereKey($candidates->pluck('customer_id')->filter()->unique()->all())->get(['id', 'display_name'])->keyBy('id');
                $quotations = Quotation::query()->whereKey($candidates->pluck('quotation_id')->filter()->unique()->all())->get(['id', 'quotation_number'])->keyBy('id');

                foreach ($candidates as $candidate) {
                    if ($processed++ >= $limit) {
                        return false;
                    }
                    try {
                        $result[$this->deliver((int) $candidate->getKey(), $now, $organizations, $users, $customers, $quotations)]++;
                    } catch (Throwable $exception) {
                        $result['failed']++;
                        report($exception);
                    }
                }
            });

        return $result;
    }

    /** @return 'sent'|'skipped' */
    private function deliver(int $id, CarbonImmutable $now, Collection $organizations, Collection $users, Collection $customers, Collection $quotations): string
    {
        return DB::transaction(function () use ($id, $now, $organizations, $users, $customers, $quotations) {
            $followUp = FollowUp::query()->whereKey($id)->lockForUpdate()->first();
            // Claimed by a concurrent run, rescheduled or closed meanwhile.
            if (! $followUp || $followUp->remind_at === null || $followUp->remind_at->greaterThan($now)) {
                return 'skipped';
            }

            $organization = $organizations->get($followUp->organization_id) ?? Organization::query()->find($followUp->organization_id);
            $assignee = $followUp->assigned_user_id === null ? null
                : ($users->get($followUp->assigned_user_id) ?? User::query()->find($followUp->assigned_user_id));
            $sent = 0;
            if ($organization && $organization->status === 'active' && $assignee
                && $followUp->status === FollowUpStatus::Open && $this->access->canView($assignee, $followUp)) {
                $sent = $this->publish($organization, $assignee, $followUp, $customers, $quotations);
            }

            FollowUp::query()->whereKey($followUp->getKey())->update([
                'remind_at' => null,
                'notified_at' => $sent > 0 ? $now : $followUp->notified_at,
            ]);

            return $sent > 0 ? 'sent' : 'skipped';
        });
    }

    private function publish(Organization $organization, User $assignee, FollowUp $followUp, Collection $customers, Collection $quotations): int
    {
        $customer = $followUp->customer_id && $this->access->allows($assignee, $organization, 'customers.view')
            ? ($customers->get($followUp->customer_id)?->display_name) : null;
        $quotation = $followUp->quotation_id && $this->access->allows($assignee, $organization, 'quotations.view')
            ? ($quotations->get($followUp->quotation_id)?->quotation_number) : null;
        $due = $followUp->due_at !== null
            ? BusinessTime::toLocal($organization, $followUp->due_at)->format('d/m/Y H:i')
            : CarbonImmutable::parse((string) $followUp->due_date)->format('d/m/Y');
        $context = implode(' · ', array_filter([$customer, $quotation]));
        $occurrence = $followUp->remind_at->utc()->format('Y-m-d\TH:i:s\Z');

        // Stored French text (existing convention) + i18n keys/params for the viewer's language.
        return $this->publisher->publishToUser($assignee, $organization, NotificationCategory::Sales, NotificationSeverity::Info,
            'Suivi à effectuer : '.$followUp->title,
            'Échéance : '.$due.($context !== '' ? ' — '.$context : ''),
            '/work?tab=all&assigned_to=all&focus='.$followUp->getKey(),
            'follow_up', $followUp->getKey(), self::EVENT_PREFIX.$occurrence.':v'.$followUp->version,
            [
                'follow_up_id' => (int) $followUp->getKey(),
                'occurrence' => $occurrence,
                'i18n' => [
                    'title' => 'work.notifyTitle',
                    'message' => $context !== '' ? 'work.notifyMessageContext' : 'work.notifyMessage',
                    'params' => ['title' => $followUp->title, 'due' => $due, 'context' => $context],
                ],
            ]);
    }
}
