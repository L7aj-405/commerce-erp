<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\AuthorizesDocumentAction;
use App\Enums\DeliveryNoteStatus;
use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderStatus;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentSellerProfile;
use App\Services\DocumentSnapshotVerifier;
use App\Services\DocumentTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateFullDeliveryNoteFromSalesOrderAction
{
    use AuthorizesDocumentAction;

    public function __construct(
        private readonly DocumentSnapshotVerifier $verifier,
        private readonly DocumentSellerProfile $sellerProfile,
        private readonly DocumentTemplateRegistry $templates,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(User $actor, SalesOrder $order, array $data = []): DeliveryNote
    {
        $this->authorizeOrderDocument($actor, $order, 'delivery_notes.create');
        $deliveryDate = $data['delivery_date'] ?? now()->toDateString();
        $this->authorizeBusinessDate($actor, $order->organization, $deliveryDate, 'delivery_notes.backdate');

        return DB::transaction(function () use ($actor, $order, $data, $deliveryDate) {
            $order = SalesOrder::query()
                ->where('organization_id', $order->organization_id)->where('store_id', $order->store_id)
                ->whereKey($order->getKey())->lockForUpdate()->with(['lines', 'customer', 'organization', 'store'])->firstOrFail();
            if ($order->status !== SalesOrderStatus::Confirmed || $order->fulfillment_status !== SalesOrderFulfillmentStatus::Fulfilled) {
                throw ValidationException::withMessages(['order' => 'Only a confirmed, fulfilled Sales Order can receive a full Delivery Note.']);
            }
            if ($order->lines->isEmpty()) {
                throw ValidationException::withMessages(['order' => 'A Sales Order must have lines before delivery can be documented.']);
            }
            if (DeliveryNote::query()->where('organization_id', $order->organization_id)->where('sales_order_id', $order->getKey())
                ->whereIn('status', [DeliveryNoteStatus::Draft->value, DeliveryNoteStatus::Issued->value])->exists()) {
                throw ValidationException::withMessages(['order' => 'This Sales Order already has an active full Delivery Note.']);
            }

            $note = new DeliveryNote;
            $note->organization_id = $order->organization_id;
            $note->store_id = $order->store_id;
            $note->sales_order_id = $order->getKey();
            $note->delivery_note_number = null;
            $note->status = DeliveryNoteStatus::Draft;
            $note->delivery_date = $deliveryDate;
            $note->recipient_name = $order->customer_name;
            $note->recipient_company = $order->customer_company;
            $note->recipient_phone = $order->customer_phone;
            $note->delivery_address = $order->customer?->billing_address;
            $note->notes = $data['notes'] ?? null;
            $note->seller_snapshot = $this->sellerProfile->snapshot($order->organization, $order->store);
            $note->template_version = $this->templates->currentDeliveryNoteVersion();
            $note->save();

            foreach ($order->lines as $source) {
                $line = new DeliveryNoteLine;
                $line->organization_id = $order->organization_id;
                $line->delivery_note_id = $note->getKey();
                $line->sales_order_line_id = $source->getKey();
                $line->product_variant_id = $source->product_variant_id;
                $line->position = $source->position;
                $line->line_type = $source->line_type;
                $line->description = $source->product_name;
                foreach (['product_name', 'variant_name', 'sku', 'reference', 'unit_label', 'quantity'] as $field) {
                    $line->{$field} = $source->{$field};
                }
                $line->save();
            }

            $this->verifier->verifyDeliveryNote($note);
            $this->audit->record('delivery_note.draft_created', $actor, $order->organization, $order->store, $note, newValues: [
                'sales_order_number' => $order->order_number, 'delivery_date' => $deliveryDate,
                'status' => DeliveryNoteStatus::Draft->value,
            ]);

            return $note->load(['lines', 'salesOrder']);
        });
    }
}
