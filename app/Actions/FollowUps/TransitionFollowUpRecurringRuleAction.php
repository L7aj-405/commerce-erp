<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpRecurringStatus;
use App\Models\FollowUpRecurringRule;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpRecurringGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W3 rule lifecycle: active ⇄ paused, active | paused → ended (final).
 * Never touches occurrences already generated. Resume starts again from the
 * organization's today (no backlog) and refuses while the rule would only be
 * paused again (assignee without access, quotation no longer current).
 */
class TransitionFollowUpRecurringRuleAction
{
    public const TRANSITIONS = ['pause', 'resume', 'end'];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly FollowUpRecurringGenerator $generator,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, FollowUpRecurringRule $rule, array $data): FollowUpRecurringRule
    {
        $data = Validator::make($data, [
            'transition' => ['required', Rule::in(self::TRANSITIONS)],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $organization, $rule, $data) {
            $locked = FollowUpRecurringRule::query()->where('organization_id', $organization->getKey())->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($this->access->canManage($actor, $locked));
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $locked->version) {
                throw ValidationException::withMessages(['version' => 'Cette récurrence a été modifiée entre-temps. Rechargez la page puis réessayez.']);
            }

            $from = $locked->status;
            $transition = (string) $data['transition'];
            $allowed = match ($transition) {
                'pause' => $from === FollowUpRecurringStatus::Active,
                'resume' => $from === FollowUpRecurringStatus::Paused,
                'end' => $from !== FollowUpRecurringStatus::Ended,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Ce changement de statut n’est pas possible depuis l’état actuel de la récurrence.']);
            }

            if ($transition !== 'resume') {
                $this->generator->stop($organization, $locked, $transition === 'end' ? FollowUpRecurringStatus::Ended : FollowUpRecurringStatus::Paused, 'manual', $actor);

                return $locked;
            }

            $reason = $this->generator->blocker($organization, $locked);
            if ($reason !== null) {
                throw ValidationException::withMessages(['status' => $reason === 'assignee_lost'
                    ? 'La personne assignée n’a plus accès à ce travail : réassignez la récurrence avant de la reprendre.'
                    : 'Le devis lié n’est plus actif : retirez-le de la récurrence avant de la reprendre.']);
            }
            $locked->status = FollowUpRecurringStatus::Active;
            $locked->status_reason = null;
            $locked->version = (int) $locked->version + 1;
            $this->generator->reschedule($organization, $locked);
            $locked->save();
            $this->audit->record('follow_up_recurring.resumed', $actor, $organization, null, $locked,
                oldValues: ['status' => $from->value], newValues: ['recurring_rule_id' => (int) $locked->getKey(), 'status' => 'active', 'next_occurrence_on' => $locked->next_occurrence_on]);
            $this->generator->generate($organization, $locked);

            return $locked;
        });
    }
}
