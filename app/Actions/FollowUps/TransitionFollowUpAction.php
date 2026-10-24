<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical follow-up lifecycle: open → completed | cancelled, and
 * completed | cancelled → open. Runs under a row lock and re-checks the
 * current status, so a second tab (or a retried request) cannot complete an
 * already-cancelled item or double-apply a transition.
 */
class TransitionFollowUpAction
{
    public const TRANSITIONS = ['complete', 'cancel', 'reopen'];

    public function __construct(
        private readonly FollowUpAccess $access,
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

            $metadata = ['follow_up_id' => $locked->getKey(), 'assigned_user_id' => (int) $locked->assigned_user_id,
                'customer_id' => $locked->customer_id, 'quotation_id' => $locked->quotation_id];
            match ($transition) {
                'complete' => $this->complete($locked, $actor, $data['outcome_note'] ?? null),
                'cancel' => $this->cancel($locked),
                'reopen' => $this->reopen($locked),
            };
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $event = match ($transition) { 'complete' => 'follow_up.completed', 'cancel' => 'follow_up.cancelled', 'reopen' => 'follow_up.reopened' };
            $this->audit->record($event, $actor, $organization, $locked->store_id ? Store::query()->find($locked->store_id) : null, $locked,
                oldValues: ['status' => $from->value],
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
    }

    private function cancel(FollowUp $followUp): void
    {
        $followUp->status = FollowUpStatus::Cancelled;
        $followUp->cancelled_at = now();
    }

    private function reopen(FollowUp $followUp): void
    {
        $followUp->status = FollowUpStatus::Open;
        $followUp->completed_at = null;
        $followUp->completed_by_user_id = null;
        $followUp->cancelled_at = null;
        $followUp->outcome_note = null;
    }
}
