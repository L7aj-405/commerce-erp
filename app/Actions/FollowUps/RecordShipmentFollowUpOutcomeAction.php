<?php

namespace App\Actions\FollowUps;

use App\Actions\Fulfillment\MarkFulfillmentDeliveredAction;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Fulfillment;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * F1.3 reception outcome for a shipment-linked follow-up. All generic work
 * mutations still pass through the canonical follow-up actions; confirming
 * delivery passes through MarkFulfillmentDeliveredAction. This action adds
 * only shipment-specific outcome metadata and business timeline events.
 */
final class RecordShipmentFollowUpOutcomeAction
{
    public const RECEIVED_OK = 'received_ok';
    public const NOT_RECEIVED = 'not_received';
    public const UNREACHABLE = 'customer_unreachable';
    public const DELIVERY_PROBLEM = 'delivery_problem';
    public const OUTCOMES = [self::RECEIVED_OK, self::NOT_RECEIVED, self::UNREACHABLE, self::DELIVERY_PROBLEM];

    public function __construct(
        private readonly SaveFollowUpAction $save,
        private readonly TransitionFollowUpAction $transition,
        private readonly MarkFulfillmentDeliveredAction $deliver,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, Organization $organization, FollowUp $followUp, array $data): FollowUp
    {
        $data = Validator::make($data, [
            'outcome' => ['required', Rule::in(self::OUTCOMES)],
            'outcome_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'required_with:due_time'],
            'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'mark_delivered' => ['sometimes', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ])->validate();

        if (in_array($data['outcome'], [self::NOT_RECEIVED, self::UNREACHABLE], true) && empty($data['due_date'])) {
            throw ValidationException::withMessages(['due_date' => 'Choisissez la prochaine date de suivi.']);
        }

        return DB::transaction(function () use ($actor, $organization, $followUp, $data) {
            $current = FollowUp::query()->where('organization_id', $organization->getKey())
                ->whereKey($followUp->getKey())->firstOrFail();
            if ($current->fulfillment_id === null || $current->sales_order_id === null) {
                throw ValidationException::withMessages(['outcome' => 'Ce suivi n’est pas lié à une expédition.']);
            }
            if ($current->status !== FollowUpStatus::Open) {
                if ($current->shipment_outcome === $data['outcome']) {
                    return $current;
                }
                throw ValidationException::withMessages(['status' => 'Ce suivi est déjà terminé.']);
            }

            $fulfillment = Fulfillment::query()->where('organization_id', $organization->getKey())
                ->whereKey($current->fulfillment_id)->firstOrFail();
            $order = $fulfillment->salesOrder()->where('organization_id', $organization->getKey())->firstOrFail();

            if ($data['outcome'] === self::RECEIVED_OK) {
                if (($data['mark_delivered'] ?? true) && ! $fulfillment->status->isDelivered()) {
                    $this->deliver->execute($actor, $order, $fulfillment);
                }
                $current = $this->transition->execute($actor, $organization, $current, [
                    'transition' => 'complete', 'outcome_note' => $data['outcome_note'] ?? null,
                    'expected_version' => $data['expected_version'],
                ]);
            } elseif ($data['outcome'] === self::DELIVERY_PROBLEM) {
                $current = $this->transition->execute($actor, $organization, $current, [
                    'transition' => 'complete', 'outcome_note' => $data['outcome_note'] ?? null,
                    'expected_version' => $data['expected_version'],
                ]);
            } else {
                $current = $this->save->update($actor, $organization, $current, [
                    'due_date' => $data['due_date'], 'due_time' => $data['due_time'] ?? null,
                    'expected_version' => $data['expected_version'],
                ]);
            }

            $locked = FollowUp::query()->where('organization_id', $organization->getKey())
                ->whereKey($current->getKey())->lockForUpdate()->firstOrFail();
            $locked->shipment_outcome = $data['outcome'];
            $locked->shipment_outcome_at = now();
            if (in_array($data['outcome'], [self::NOT_RECEIVED, self::UNREACHABLE], true)) {
                $note = trim((string) ($data['outcome_note'] ?? ''));
                $locked->outcome_note = $note === '' ? null : $note;
            }
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $event = match ($data['outcome']) {
                self::RECEIVED_OK => 'shipment_follow_up.reception_confirmed',
                self::NOT_RECEIVED => 'shipment_follow_up.not_received',
                self::UNREACHABLE => 'shipment_follow_up.client_unreachable',
                self::DELIVERY_PROBLEM => 'shipment_follow_up.delivery_problem',
            };
            $this->audit->record($event, $actor, $organization, Store::query()->find($locked->store_id), $locked,
                newValues: [
                    'follow_up_id' => (int) $locked->getKey(), 'sales_order_id' => (int) $locked->sales_order_id,
                    'fulfillment_id' => $locked->fulfillment_id, 'outcome' => $data['outcome'],
                    'due_date' => $locked->due_date, 'due_at' => $locked->due_at?->utc()->toIso8601String(),
                    'support_path' => $data['outcome'] === self::DELIVERY_PROBLEM ? 'sales_order' : null,
                ]);

            return $locked;
        });
    }
}
