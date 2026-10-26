<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpRecurrenceFrequency;
use App\Enums\FollowUpRecurringStatus;
use App\Enums\QuotationStatus;
use App\Models\FollowUpRecurringRule;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpRecurringGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W3 canonical create / update of recurring follow-up rules, for every
 * transport. Authorization, links and assignment reuse the W1/W1.1 rules
 * through SaveFollowUpAction (own rule with follow_ups.create/update; another
 * assignee or Unassigned needs manage_all; the store comes only from a linked
 * quotation, which must still be actionable).
 *
 * Edits never touch occurrences already generated (including the one held
 * ahead): changes apply from the next generated occurrence. Schedule changes
 * recompute the cursor from the organization's today. Ended rules are final.
 */
class SaveFollowUpRecurringRuleAction
{
    private const SCHEDULE_FIELDS = ['frequency', 'interval_count', 'weekdays', 'month_day', 'local_time', 'starts_on', 'ends_on', 'max_occurrences'];

    private const CONTENT_FIELDS = ['title', 'note', 'priority', 'customer_id', 'quotation_id', 'assigned_user_id'];

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly SaveFollowUpAction $followUps,
        private readonly FollowUpRecurringGenerator $generator,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(FollowUpPriority::class)],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'quotation_id' => ['sometimes', 'nullable', 'integer'],
            'frequency' => [$required, Rule::enum(FollowUpRecurrenceFrequency::class)],
            'interval_count' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'weekdays' => ['sometimes', 'nullable', 'array', 'max:7'],
            'weekdays.*' => ['integer', 'distinct', 'min:1', 'max:7'],
            'month_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'local_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'max_occurrences' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FollowUpRecurringRule
    {
        $this->access->authorize($this->access->canCreate($actor, $organization));
        $data = Validator::make($data, self::rules(true))->validate();

        return DB::transaction(function () use ($actor, $organization, $data) {
            $rule = new FollowUpRecurringRule;
            $rule->organization_id = $organization->getKey();
            $rule->created_by_user_id = $actor->getKey();
            $rule->status = FollowUpRecurringStatus::Active;
            $rule->priority = FollowUpPriority::Normal;
            $rule->interval_count = 1;
            $rule->occurrences_generated = 0;
            $rule->version = 1;
            $this->fill($rule, $data);
            $this->assertSchedule($rule);

            $this->followUps->applyLinks($actor, $organization, $rule, $data);
            $this->assertQuotationActionable($organization, $rule);
            $assigneeId = $this->followUps->requestedAssignee($actor, $organization, $data, (int) $actor->getKey());
            $this->followUps->assertAssignee($actor, $organization, $assigneeId, $rule->store_id);
            $rule->assigned_user_id = $assigneeId;

            $this->generator->reschedule($organization, $rule);
            $rule->save();
            $this->audit->record('follow_up_recurring.created', $actor, $organization, null, $rule, newValues: $this->snapshot($rule));

            // The first occurrence (today's, or the next future one) exists immediately.
            $this->generator->generate($organization, $rule);

            return $rule;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Organization $organization, FollowUpRecurringRule $rule, array $data): FollowUpRecurringRule
    {
        $data = Validator::make($data, self::rules(false))->validate();

        return DB::transaction(function () use ($actor, $organization, $rule, $data) {
            $locked = FollowUpRecurringRule::query()->where('organization_id', $organization->getKey())->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($this->access->canManage($actor, $locked));
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $locked->version) {
                throw ValidationException::withMessages(['version' => 'Cette récurrence a été modifiée entre-temps. Rechargez la page puis réessayez.']);
            }
            if ($locked->status === FollowUpRecurringStatus::Ended) {
                throw ValidationException::withMessages(['status' => 'Une récurrence terminée ne peut plus être modifiée.']);
            }

            $before = $this->snapshot($locked);
            $this->fill($locked, $data);
            $this->assertSchedule($locked);
            $this->followUps->applyLinks($actor, $organization, $locked, $data);
            if ($locked->quotation_id !== $before['quotation_id']) {
                $this->assertQuotationActionable($organization, $locked);
            }
            $previousAssignee = $before['assigned_user_id'];
            $assigneeId = $this->followUps->requestedAssignee($actor, $organization, $data, $previousAssignee);
            if ($assigneeId !== $previousAssignee || $locked->store_id !== $before['store_id']) {
                $this->followUps->assertAssignee($actor, $organization, $assigneeId, $locked->store_id, $assigneeId !== $previousAssignee);
            }
            $locked->assigned_user_id = $assigneeId;

            $after = $this->snapshot($locked);
            $changed = array_keys(array_filter(array_merge(array_flip(self::SCHEDULE_FIELDS), array_flip(self::CONTENT_FIELDS)),
                fn ($_, string $field) => $before[$field] !== $after[$field], ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $locked;
            }

            if (array_intersect($changed, self::SCHEDULE_FIELDS) !== []) {
                $this->generator->reschedule($organization, $locked);
            }
            $locked->version = (int) $locked->version + 1;
            $locked->save();
            $this->audit->record('follow_up_recurring.updated', $actor, $organization, null, $locked,
                oldValues: array_intersect_key($before, array_flip($changed)),
                newValues: ['recurring_rule_id' => (int) $locked->getKey(), 'changed_fields' => $changed, ...array_intersect_key($after, array_flip($changed))]);
            $this->generator->generate($organization, $locked);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    private function fill(FollowUpRecurringRule $rule, array $data): void
    {
        if (array_key_exists('title', $data)) $rule->title = trim((string) $data['title']);
        if (array_key_exists('note', $data)) $rule->note = trim((string) $data['note']) === '' ? null : trim((string) $data['note']);
        if (array_key_exists('priority', $data)) $rule->priority = FollowUpPriority::from((string) $data['priority']);
        if (array_key_exists('frequency', $data)) $rule->frequency = FollowUpRecurrenceFrequency::from((string) $data['frequency']);
        foreach (['interval_count', 'month_day', 'max_occurrences'] as $field) {
            if (array_key_exists($field, $data)) $rule->{$field} = $data[$field] === null ? null : (int) $data[$field];
        }
        foreach (['local_time', 'starts_on', 'ends_on'] as $field) {
            if (array_key_exists($field, $data)) $rule->{$field} = $data[$field];
        }
        if (array_key_exists('weekdays', $data)) {
            $days = array_values(array_unique(array_map('intval', (array) ($data['weekdays'] ?? []))));
            sort($days);
            $rule->weekdays = $days === [] ? null : $days;
        }

        // Only the fields the frequency uses are kept.
        if ($rule->frequency !== FollowUpRecurrenceFrequency::Weekly) $rule->weekdays = null;
        if ($rule->frequency !== FollowUpRecurrenceFrequency::Monthly) $rule->month_day = null;
    }

    private function assertSchedule(FollowUpRecurringRule $rule): void
    {
        $errors = [];
        if ($rule->frequency === FollowUpRecurrenceFrequency::Weekly && empty($rule->weekdays)) {
            $errors['weekdays'] = 'Choisissez au moins un jour de la semaine.';
        }
        if ($rule->frequency === FollowUpRecurrenceFrequency::Monthly && $rule->month_day === null) {
            $errors['month_day'] = 'Choisissez le jour du mois.';
        }
        if ($rule->ends_on !== null && $rule->ends_on < $rule->starts_on) {
            $errors['ends_on'] = 'La date de fin doit être postérieure ou égale à la date de début.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assertQuotationActionable(Organization $organization, FollowUpRecurringRule $rule): void
    {
        if ($rule->quotation_id === null) {
            return;
        }
        $status = Quotation::query()->where('organization_id', $organization->getKey())->whereKey($rule->quotation_id)->value('status');
        $status = $status instanceof QuotationStatus ? $status : QuotationStatus::tryFrom((string) $status);
        if (! $status || ! FollowUpRecurringGenerator::quotationIsActionable($status)) {
            throw ValidationException::withMessages(['quotation_id' => 'Ce devis n’est plus actif (converti ou remplacé) : une récurrence ne peut pas s’y rattacher.']);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(FollowUpRecurringRule $rule): array
    {
        $int = fn ($value) => $value === null ? null : (int) $value;

        return [
            'recurring_rule_id' => $int($rule->getKey()),
            'title' => $rule->title, 'note' => $rule->note, 'priority' => $rule->priority?->value,
            'frequency' => $rule->frequency?->value, 'interval_count' => $int($rule->interval_count),
            'weekdays' => $rule->weekdays, 'month_day' => $int($rule->month_day), 'local_time' => $rule->local_time,
            'starts_on' => $rule->starts_on, 'ends_on' => $rule->ends_on, 'max_occurrences' => $int($rule->max_occurrences),
            'customer_id' => $int($rule->customer_id), 'quotation_id' => $int($rule->quotation_id), 'store_id' => $int($rule->store_id),
            'assigned_user_id' => $int($rule->assigned_user_id),
        ];
    }
}
