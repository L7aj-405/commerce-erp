<?php

namespace App\Actions\FollowUps;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Models\Customer;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FollowUps\FollowUpAccess;
use App\Services\FollowUps\FollowUpAssignees;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Canonical create / update / reschedule / reassign for follow-ups, shared by
 * every transport. The organization always comes from trusted server context;
 * the store is derived from a linked quotation, never chosen by the client.
 *
 * Updates are partial (only supplied keys change) and accept an optional
 * `expected_version` that rejects writes from a stale copy.
 *
 * Due input contract: either `due_date` (Y-m-d) + optional `due_time` (H:i)
 * in the organization business timezone, or `due_at` as ISO 8601 with an
 * explicit offset. Bare datetimes without an offset are rejected.
 */
class SaveFollowUpAction
{
    private const ISO_WITH_OFFSET = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/';

    public function __construct(
        private readonly FollowUpAccess $access,
        private readonly FollowUpAssignees $assignees,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::enum(FollowUpPriority::class)],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'quotation_id' => ['sometimes', 'nullable', 'integer'],
            // No `sometimes`: a due_time sent without a due_date must fail.
            'due_date' => ['nullable', 'date_format:Y-m-d', 'required_with:due_time'],
            'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'due_at' => ['sometimes', 'nullable', 'string', 'regex:'.self::ISO_WITH_OFFSET, 'prohibits:due_date,due_time'],
            'expected_version' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, Organization $organization, array $data): FollowUp
    {
        $this->access->authorize($this->access->canCreate($actor, $organization));
        $data = Validator::make($data, self::rules(true))->validate();

        return DB::transaction(function () use ($actor, $organization, $data) {
            $followUp = new FollowUp;
            $followUp->organization_id = $organization->getKey();
            $followUp->created_by_user_id = $actor->getKey();
            $followUp->status = FollowUpStatus::Open;
            $followUp->priority = FollowUpPriority::tryFrom((string) ($data['priority'] ?? '')) ?? FollowUpPriority::Normal;
            $followUp->title = trim((string) $data['title']);
            $followUp->note = $this->text($data['note'] ?? null);
            $followUp->version = 1;

            $this->applyLinks($actor, $organization, $followUp, $data);
            $assigneeId = (int) ($data['assigned_user_id'] ?? $actor->getKey());
            $this->assertAssignee($actor, $organization, $assigneeId, $followUp->store_id);
            $followUp->assigned_user_id = $assigneeId;
            $this->applyDue($organization, $followUp, $data);
            $followUp->save();

            $this->audit->record('follow_up.created', $actor, $organization, $this->store($followUp), $followUp, newValues: [
                ...$this->reference($followUp), ...$this->dueSnapshot($followUp), 'title' => $followUp->title, 'priority' => $followUp->priority->value,
            ]);

            return $followUp;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Organization $organization, FollowUp $followUp, array $data): FollowUp
    {
        $data = Validator::make($data, self::rules(false))->validate();

        return DB::transaction(function () use ($actor, $organization, $followUp, $data) {
            $locked = $this->lock($organization, $followUp);
            $this->access->authorize($this->access->canManage($actor, $locked));
            $this->assertVersion($locked, $data);
            if ($locked->status !== FollowUpStatus::Open) {
                throw ValidationException::withMessages(['status' => 'Seul un suivi ouvert peut être modifié. Rouvrez-le d’abord.']);
            }

            $before = ['assignee' => (int) $locked->assigned_user_id, 'due' => $this->dueSnapshot($locked)];
            $original = $locked->only(['title', 'note', 'priority', 'customer_id', 'quotation_id', 'store_id']);

            if (array_key_exists('title', $data)) $locked->title = trim((string) $data['title']);
            if (array_key_exists('note', $data)) $locked->note = $this->text($data['note']);
            if (array_key_exists('priority', $data)) $locked->priority = FollowUpPriority::from((string) $data['priority']);
            $this->applyLinks($actor, $organization, $locked, $data);
            $this->applyDue($organization, $locked, $data);

            $assigneeId = array_key_exists('assigned_user_id', $data) && $data['assigned_user_id'] !== null
                ? (int) $data['assigned_user_id'] : (int) $locked->assigned_user_id;
            if ($assigneeId !== $before['assignee'] || $locked->store_id !== $original['store_id']) {
                $this->assertAssignee($actor, $organization, $assigneeId, $locked->store_id, $assigneeId !== $before['assignee']);
            }
            $locked->assigned_user_id = $assigneeId;

            $after = $this->dueSnapshot($locked);
            $changed = array_values(array_filter(
                ['title', 'note', 'priority', 'customer_id', 'quotation_id'],
                fn (string $field) => $locked->{$field} != $original[$field],
            ));
            $rescheduled = $after !== $before['due'];
            $reassigned = $assigneeId !== $before['assignee'];
            if ($changed === [] && ! $rescheduled && ! $reassigned) {
                return $locked;
            }

            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $store = $this->store($locked);
            $reference = $this->reference($locked);
            if ($changed !== []) {
                $this->audit->record('follow_up.updated', $actor, $organization, $store, $locked, newValues: [...$reference, 'changed_fields' => $changed]);
            }
            if ($rescheduled) {
                $this->audit->record('follow_up.rescheduled', $actor, $organization, $store, $locked, oldValues: $before['due'], newValues: [...$reference, ...$after]);
            }
            if ($reassigned) {
                $this->audit->record('follow_up.reassigned', $actor, $organization, $store, $locked,
                    oldValues: ['assigned_user_id' => $before['assignee']], newValues: [...$reference, 'assigned_user_id' => $assigneeId]);
            }

            return $locked;
        });
    }

    private function lock(Organization $organization, FollowUp $followUp): FollowUp
    {
        return FollowUp::query()->where('organization_id', $organization->getKey())->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function assertVersion(FollowUp $followUp, array $data): void
    {
        if (isset($data['expected_version']) && (int) $data['expected_version'] !== (int) $followUp->version) {
            throw ValidationException::withMessages(['version' => 'Ce suivi a été modifié entre-temps. Rechargez la page puis réessayez.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyLinks(User $actor, Organization $organization, FollowUp $followUp, array $data): void
    {
        $quotationChanged = array_key_exists('quotation_id', $data);
        $customerChanged = array_key_exists('customer_id', $data);
        if (! $quotationChanged && ! $customerChanged) {
            return;
        }

        $quotationId = $quotationChanged ? $data['quotation_id'] : $followUp->quotation_id;
        $customerId = $customerChanged ? $data['customer_id'] : $followUp->customer_id;
        $quotation = null;

        if ($quotationId !== null) {
            $quotation = Quotation::query()->where('organization_id', $organization->getKey())->whereKey((int) $quotationId)->first(['id', 'organization_id', 'store_id', 'customer_id']);
            if (! $quotation) {
                throw ValidationException::withMessages(['quotation_id' => 'Devis introuvable dans cette organisation.']);
            }
            if ((int) $quotation->getKey() !== (int) $followUp->quotation_id
                && (! $actor->hasPermission($organization, 'quotations.view') || ! $this->access->hasStoreAccess($actor, $quotation->store_id))) {
                throw ValidationException::withMessages(['quotation_id' => 'Vous n’avez pas accès à ce devis.']);
            }
            if ($quotation->customer_id !== null) {
                if ($customerChanged && $customerId !== null && (int) $customerId !== (int) $quotation->customer_id) {
                    throw ValidationException::withMessages(['customer_id' => 'Le client doit correspondre au client du devis.']);
                }
                $customerId = $quotation->customer_id;
            }
        }

        if ($customerId !== null) {
            $exists = Customer::query()->where('organization_id', $organization->getKey())->whereKey((int) $customerId)->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['customer_id' => 'Client introuvable dans cette organisation.']);
            }
            if ((int) $customerId !== (int) $followUp->customer_id && ! $actor->hasPermission($organization, 'customers.view')) {
                throw ValidationException::withMessages(['customer_id' => 'Vous n’avez pas accès aux clients.']);
            }
        }

        $followUp->quotation_id = $quotation?->getKey();
        $followUp->customer_id = $customerId !== null ? (int) $customerId : null;
        $followUp->store_id = $quotation?->store_id;
    }

    private function assertAssignee(User $actor, Organization $organization, int $assigneeId, ?int $storeId, bool $assigning = true): void
    {
        if ($assigning && $assigneeId !== (int) $actor->getKey()) {
            $this->access->authorize($this->access->canManageAll($actor, $organization));
        }
        if (! $this->assignees->isAssignable($organization, $assigneeId, $storeId)) {
            throw ValidationException::withMessages(['assigned_user_id' => $storeId === null
                ? 'Cet utilisateur ne peut pas recevoir de suivis dans cette organisation.'
                : 'Cet utilisateur ne peut pas recevoir de suivis pour ce magasin.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyDue(Organization $organization, FollowUp $followUp, array $data): void
    {
        if (! array_key_exists('due_date', $data) && ! array_key_exists('due_time', $data) && ! array_key_exists('due_at', $data)) {
            return;
        }

        if (filled($data['due_at'] ?? null)) {
            $instant = CarbonImmutable::parse((string) $data['due_at'])->utc();
            $followUp->due_at = $instant;
            $followUp->due_date = BusinessTime::toLocal($organization, $instant)->toDateString();

            return;
        }

        $date = $data['due_date'] ?? null;
        $time = $data['due_time'] ?? null;
        if ($date === null) {
            $followUp->due_date = null;
            $followUp->due_at = null;

            return;
        }
        if ($time === null) {
            $followUp->due_date = $date;
            $followUp->due_at = null;

            return;
        }

        try {
            $followUp->due_at = BusinessTime::localToUtc($organization, (string) $date, (string) $time);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['due_time' => 'Cette heure n’existe pas dans le fuseau horaire de l’organisation (changement d’heure).']);
        }
        $followUp->due_date = $date;
    }

    /** @return array{due_date: string|null, due_at: string|null} */
    private function dueSnapshot(FollowUp $followUp): array
    {
        return [
            'due_date' => $followUp->due_date,
            'due_at' => $followUp->due_at?->utc()->toIso8601String(),
        ];
    }

    /** @return array<string, int|null> */
    private function reference(FollowUp $followUp): array
    {
        return [
            'follow_up_id' => $followUp->getKey(),
            'assigned_user_id' => (int) $followUp->assigned_user_id,
            'customer_id' => $followUp->customer_id,
            'quotation_id' => $followUp->quotation_id,
        ];
    }

    private function store(FollowUp $followUp): ?Store
    {
        return $followUp->store_id ? Store::query()->find($followUp->store_id) : null;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
