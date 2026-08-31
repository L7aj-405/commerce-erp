<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelDeliveryNoteDraftAction
{
    use AuthorizesDocumentAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, DeliveryNote $note, ?string $reason = null): DeliveryNote
    {
        $this->authorizeDeliveryNote($actor, $note, 'delivery_notes.update_draft');

        return DB::transaction(function () use ($actor, $note, $reason) {
            $note = DeliveryNote::query()->where('organization_id', $note->organization_id)->where('store_id', $note->store_id)
                ->whereKey($note->getKey())->lockForUpdate()->firstOrFail();
            if ($note->status !== DeliveryNoteStatus::Draft) {
                throw ValidationException::withMessages(['delivery_note' => 'Only a draft Delivery Note can be cancelled.']);
            }
            $note->status = DeliveryNoteStatus::Cancelled;
            $note->cancelled_at = now();
            $note->cancelled_by_user_id = $actor->getKey();
            $note->cancellation_reason = $reason;
            $note->save();
            $this->audit->record('delivery_note.draft_cancelled', $actor, $note->organization, $note->store, $note, oldValues: ['status' => DeliveryNoteStatus::Draft->value], newValues: [
                'sales_order_number' => $note->salesOrder->order_number, 'status' => DeliveryNoteStatus::Cancelled->value,
                'delivery_date' => $note->delivery_date->toDateString(), 'reason' => $reason,
            ]);

            return $note;
        });
    }
}
