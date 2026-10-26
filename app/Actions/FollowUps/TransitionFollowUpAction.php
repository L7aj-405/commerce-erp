<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpAssignees;
use App\Services\FollowUps\FollowUpReminderSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical follow-up lifecycle: open → completed | cancelled, and
 * completed | cancelled → open. Runs under a row lock and re-checks the
 * current status, so a second tab (or a retried request) cannot complete an
 * already-cancelled item or double-apply a transition.
 *
 * Reopening history whose assignee has since lost access makes the item
 * "Unassigned" (W1.1) instead of recreating invisible work.
 *
 * W2 reminders: complete/cancel drop the pending occurrence; reopen arms a
 * new one only when the reminder instant is still in the future (reopening
 * overdue work never re-notifies, so repeated reopen cannot loop).
 */
class TransitionFollowUpAction
{
    public const TRANSITIONS = ['complete', 'cancel', 'reopen'];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly FollowUpAssignees $assignees,
        private readonly FollowUpReminderSchedule $reminders,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'transition' => ['required', Rule::in(self::TRANSITIONS)],
            'outcome_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, FollowUp $followUp, array $data): FollowUp
    {
        $data = Validator::make($data, self::rules())->validate();

        return DB::transaction(function () use ($actor, $organization, $followUp, $data) {
            $locked = FollowUp::query()->where('organization_id', $organization->getKey())->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($this->access->canManage($actor, $locked));
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $locked->version) {
                throw ValidationException::withMessages(['version' => 'Ce suivi a été modifié entre-temps. Rechargez la page puis réessayez.']);
            }

            $from = $locked->status;
            $transition = (string) $data['transition'];
            $allowed = $transition === 'reopen' ? $from !== FollowUpStatus::Open : $from === FollowUpStatus::Open;
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Ce changement de statut n’est pas possible depuis l’état actuel du suivi.']);
            }

            $previousAssignee = $locked->assigned_user_id === null ? null : (int) $locked->assigned_user_id;
            match ($transition) {
                'complete' => $this->complete($locked, $actor, $data['outcome_note'] ?? null),
                'cancel' => $this->cancel($locked),
                'reopen' => $this->reopen($organization, $locked),
            };
            $metadata = ['follow_up_id' => $locked->getKey(), 'assigned_user_id' => $locked->assigned_user_id === null ? null : (int) $locked->assigned_user_id,
                'customer_id' => $locked->customer_id, 'quotation_id' => $locked->quotation_id];
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $event = match ($transition) { 'complete' => 'follow_up.completed', 'cancel' => 'follow_up.cancelled', 'reopen' => 'follow_up.reopened' };
            $this->audit->record($event, $actor, $organization, $locked->store_id ? Store::query()->find($locked->store_id) : null, $locked,
                oldValues: ['status' => $from->value, ...($previousAssignee !== $metadata['assigned_user_id'] ? ['assigned_user_id' => $previousAssignee] : [])],
                newValues: [...$metadata, 'status' => $locked->status->value, 'has_outcome_note' => $locked->outcome_note !== null]);

            return $locked;
        });
    }

    private function complete(FollowUp $followUp, User $actor, mixed $outcome): void
    {
        $outcome = trim((string) $outcome);
        $followUp->status = FollowUpStatus::Completed;
        $followUp->completed_at = now();
        $followUp->completed_by_user_id = $actor->getKey();
        $followUp->outcome_note = $outcome === '' ? null : $outcome;
        $followUp->remind_at = null;
    }

    private function cancel(FollowUp $followUp): void
    {
        $followUp->status = FollowUpStatus::Cancelled;
        $followUp->cancelled_at = now();
        $followUp->remind_at = null;
    }

    private function reopen(Organization $organization, FollowUp $followUp): void
    {
        if ($followUp->assigned_user_id !== null
            && ! $this->assignees->isAssignable($organization, (int) $followUp->assigned_user_id, $followUp->store_id, lock: true)) {
            $followUp->assigned_user_id = null;
        }
        $followUp->status = FollowUpStatus::Open;
        $followUp->completed_at = null;
        $followUp->completed_by_user_id = null;
        $followUp->cancelled_at = null;
        $followUp->outcome_note = null;
        $followUp->remind_at = $this->reminders->upcoming($organization, $followUp);
        if ($followUp->remind_at !== null) {
            $followUp->notified_at = null;
        }
    }
}
