<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Actions\Sales\FulfillSalesOrderAction;
use App\Enums\DeliveryNoteStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DeliveryNoteNumberGenerator;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentSnapshotVerifier;
use App\Services\DocumentTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueDeliveryNoteAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DeliveryNoteNumberGenerator $numbers,
        private readonly DocumentSnapshotVerifier $verifier,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
        private readonly FulfillSalesOrderAction $fulfillOrder,
    ) {}

    public function execute(User $actor, DeliveryNote $note): DeliveryNote
    {
        $this->authorizeDeliveryNote($actor, $note, 'delivery_notes.issue');

        return DB::transaction(function () use ($actor, $note) {
            $note = DeliveryNote::query()->where('organization_id', $note->organization_id)->where('store_id', $note->store_id)
                ->whereKey($note->getKey())->lockForUpdate()->with(['organization', 'store', 'salesOrder.lines', 'lines'])->firstOrFail();
            if ($note->status !== DeliveryNoteStatus::Draft) {
                throw ValidationException::withMessages(['delivery_note' => 'Only a draft Delivery Note can be issued.']);
            }
            $order = SalesOrder::query()->where('organization_id', $note->organization_id)->whereKey($note->sales_order_id)->lockForUpdate()->firstOrFail();
            if ($order->fulfillment_status === SalesOrderFulfillmentStatus::Unfulfilled) {
                $order = $this->fulfillOrder->execute($actor, $order)->fresh();
            }
            if ($order->fulfillment_status !== SalesOrderFulfillmentStatus::Fulfilled) {
                throw ValidationException::withMessages(['order' => 'The Sales Order could not be fulfilled.']);
            }
            if (DeliveryNote::query()->where('organization_id', $note->organization_id)->where('sales_order_id', $note->sales_order_id)
                ->where('status', DeliveryNoteStatus::Issued->value)->where('id', '!=', $note->getKey())->exists()) {
                throw ValidationException::withMessages(['order' => 'This Sales Order already has an issued full Delivery Note.']);
            }
            $this->verifier->verifyDeliveryNote($note);
            $this->sellerProfile->validate($note->seller_snapshot);
            $this->templates->deliveryNoteView($note->template_version);
            $note->delivery_note_number = $this->numbers->next($note->organization);
            $note->status = DeliveryNoteStatus::Issued;
            $note->issued_at = now();
            $note->issued_by_user_id = $actor->getKey();
            $note->save();
            $this->audit->record('delivery_note.issued', $actor, $note->organization, $note->store, $note, oldValues: ['status' => DeliveryNoteStatus::Draft->value], newValues: [
                'delivery_note_number' => $note->delivery_note_number, 'sales_order_number' => $note->salesOrder->order_number,
                'delivery_date' => $note->delivery_date->toDateString(), 'status' => DeliveryNoteStatus::Issued->value,
            ]);

            return $note;
        });
    }
}
