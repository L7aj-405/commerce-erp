<?php

namespace App\Actions\Attention;

use App\Actions\FollowUps\SaveFollowUpAction;
use App\Enums\AttentionStatus;
use App\Models\AttentionItem;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\User;
use App\Services\Attention\AttentionQuery;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * W4.1 human actions on an attention item, for every transport: snooze,
 * dismiss, and the bridge to the Work Center (create a follow-up). None of
 * them touches the quotation; creating a follow-up does not resolve the item
 * (only the detector does, when the business condition is gone).
 *
 * - snooze: hidden until a moment in the organization timezone (later today
 *   = +3 h; tomorrow / in 3 days / next week / a chosen date = 09:00 local);
 *   it reopens at that moment only if the condition still holds.
 * - dismiss: ends the item for this condition episode (optional note); a new
 *   episode — after the condition disappeared and came back — opens again.
 */
class HandleAttentionItemAction
{
    public const SNOOZE_PRESETS = ['later_today', 'tomorrow', 'in_3_days', 'next_week', 'date'];

    private const FOLLOW_UP_INPUT = ['title', 'note', 'priority', 'assigned_user_id', 'due_date', 'due_time'];

    public function __construct(
        private readonly AttentionQuery $attention,
        private readonly FollowUpAccess $access,
        private readonly SaveFollowUpAction $followUps,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function snooze(User $actor, Organization $organization, AttentionItem $item, array $data): AttentionItem
    {
        $data = Validator::make($data, [
            'preset' => ['required', Rule::in(self::SNOOZE_PRESETS)],
            'until_date' => ['required_if:preset,date', 'nullable', 'date_format:Y-m-d', 'after:'.BusinessTime::today($organization)],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        return $this->mutate($actor, $organization, $item, $data, function (AttentionItem $locked) use ($organization, $data) {
            $until = $this->snoozeUntil($organization, (string) $data['preset'], $data['until_date'] ?? null);
            $locked->status = AttentionStatus::Snoozed;
            $locked->snoozed_until = $until;

            return ['attention.snoozed', ['snoozed_until' => $until->toIso8601String(), 'preset' => $data['preset']]];
        });
    }

    /** @param array<string, mixed> $data */
    public function dismiss(User $actor, Organization $organization, AttentionItem $item, array $data): AttentionItem
    {
        $data = Validator::make($data, [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        return $this->mutate($actor, $organization, $item, $data, function (AttentionItem $locked) use ($actor, $data) {
            $note = trim((string) ($data['note'] ?? ''));
            $locked->status = AttentionStatus::Dismissed;
            $locked->snoozed_until = null;
            $locked->dismissed_at = now();
            $locked->dismissed_by_user_id = $actor->getKey();
            $locked->dismiss_note = $note === '' ? null : $note;

            return ['attention.dismissed', ['has_note' => $locked->dismiss_note !== null]];
        });
    }

    /**
     * Create a follow-up for the item's quotation through SaveFollowUpAction
     * (all follow-up permission, store, assignment and link rules apply). The
     * quotation link always comes from the item, never from the client; the
     * customer and store derive from it as usual.
     *
     * @param array<string, mixed> $data
     */
    public function createFollowUp(User $actor, Organization $organization, AttentionItem $item, array $data): FollowUp
    {
        $this->access->authorize((int) $item->organization_id === (int) $organization->getKey() && $this->attention->canView($actor, $item));
        if ($item->quotation_id === null) {
            throw ValidationException::withMessages(['quotation_id' => 'Le devis lié à cette alerte n’existe plus.']);
        }

        return DB::transaction(function () use ($actor, $organization, $item, $data) {
            $input = array_intersect_key($data, array_flip(self::FOLLOW_UP_INPUT));
            $followUp = $this->followUps->create($actor, $organization, [...$input, 'quotation_id' => (int) $item->quotation_id]);

            $locked = AttentionItem::query()->where('organization_id', $organization->getKey())->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $metadata['follow_up_ids'] = [...($metadata['follow_up_ids'] ?? []), (int) $followUp->getKey()];
            $locked->metadata = $metadata;
            $locked->save();
            $this->audit->record('attention.follow_up_created', $actor, $organization, null, $locked,
                newValues: ['attention_item_id' => (int) $locked->getKey(), 'type' => $locked->type->value, 'follow_up_id' => (int) $followUp->getKey(), 'quotation_id' => (int) $locked->quotation_id]);

            return $followUp;
        });
    }

    /** @param array<string, mixed> $data @param callable(AttentionItem): array{0: string, 1: array<string, mixed>} $change */
    private function mutate(User $actor, Organization $organization, AttentionItem $item, array $data, callable $change): AttentionItem
    {
        return DB::transaction(function () use ($actor, $organization, $item, $data, $change) {
            $locked = AttentionItem::query()->where('organization_id', $organization->getKey())->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($this->attention->canView($actor, $locked));
            if (isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $locked->version) {
                throw ValidationException::withMessages(['version' => 'Cette alerte a changé entre-temps. Rechargez la page puis réessayez.']);
            }
            if ($locked->active_key === null || ! in_array($locked->status, [AttentionStatus::Open, AttentionStatus::Snoozed], true)) {
                throw ValidationException::withMessages(['status' => 'Cette alerte n’est plus active.']);
            }

            $from = $locked->status->value;
            [$event, $values] = $change($locked);
            $locked->version = (int) $locked->version + 1;
            $locked->save();
            $this->audit->record($event, $actor, $organization, null, $locked, oldValues: ['status' => $from],
                newValues: ['attention_item_id' => (int) $locked->getKey(), 'type' => $locked->type->value, 'quotation_id' => $locked->quotation_id, 'status' => $locked->status->value, ...$values]);

            return $locked;
        });
    }

    private function snoozeUntil(Organization $organization, string $preset, ?string $date): CarbonImmutable
    {
        if ($preset === 'later_today') {
            return CarbonImmutable::now('UTC')->addHours(3);
        }
        $today = CarbonImmutable::parse(BusinessTime::today($organization), 'UTC');
        $day = match ($preset) {
            'tomorrow' => $today->addDay()->toDateString(),
            'in_3_days' => $today->addDays(3)->toDateString(),
            'next_week' => $today->addWeek()->toDateString(),
            default => (string) $date,
        };

        return BusinessTime::localToUtc($organization, $day, '09:00');
    }
}
