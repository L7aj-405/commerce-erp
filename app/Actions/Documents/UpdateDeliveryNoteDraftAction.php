<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDeliveryNoteDraftAction
{
    use AuthorizesDocumentAction;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, DeliveryNote $note, array $data): DeliveryNote
    {
        $this->authorizeDeliveryNote($actor, $note, 'delivery_notes.update_draft');
        $date = $data['delivery_date'];
        $this->authorizeBusinessDate($actor, $note->organization, $date, 'delivery_notes.backdate');

        return DB::transaction(function () use ($actor, $note, $data, $date) {
            $note = DeliveryNote::query()->where('organization_id', $note->organization_id)->where('store_id', $note->store_id)
                ->whereKey($note->getKey())->lockForUpdate()->firstOrFail();
            if ($note->status !== DeliveryNoteStatus::Draft) {
                throw ValidationException::withMessages(['delivery_note' => 'Only a draft Delivery Note can be updated.']);
            }
            $oldDate = $note->delivery_date->toDateString();
            $updatedFields = [];
            foreach (['recipient_name', 'recipient_company', 'recipient_phone', 'delivery_address', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    if ($note->{$field} !== $data[$field]) {
                        $updatedFields[] = $field;
                    }
                    $note->{$field} = $data[$field];
                }
            }
            $note->delivery_date = $date;
            $note->save();
            $this->audit->record('delivery_note.draft_updated', $actor, $note->organization, $note->store, $note, oldValues: [
                'delivery_date' => $oldDate,
            ], newValues: [
                'delivery_date' => $date, 'updated_fields' => $updatedFields, 'sales_order_number' => $note->salesOrder->order_number,
                'status' => DeliveryNoteStatus::Draft->value,
            ]);

            return $note;
        });
    }
}
