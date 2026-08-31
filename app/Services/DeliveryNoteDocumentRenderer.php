<?php

namespace App\Services;

use App\Enums\DeliveryNoteStatus;
use App\Models\DeliveryNote;

class DeliveryNoteDocumentRenderer
{
    public function __construct(
        private readonly DocumentTemplateRegistry $templates,
        private readonly DocumentValueFormatter $format,
    ) {}

    /** @return array<string, mixed> */
    public function payload(DeliveryNote $note): array
    {
        $note->loadMissing(['lines', 'salesOrder:id,order_number', 'issuedBy:id,name']);

        return [
            'kind' => 'delivery_note',
            'title' => __('documents.delivery_note', locale: config('documents.locale')),
            'watermark' => match ($note->status) {
                DeliveryNoteStatus::Draft => __('documents.draft', locale: config('documents.locale')),
                DeliveryNoteStatus::Cancelled => __('documents.cancelled', locale: config('documents.locale')),
                default => null,
            },
            'document' => [
                'number' => $note->delivery_note_number,
                'date' => $this->format->date($note->delivery_date),
                'order_number' => $note->salesOrder?->order_number,
                'notes' => $note->notes,
            ],
            'seller' => $note->seller_snapshot,
            'buyer' => [
                'name' => $note->recipient_name,
                'company' => $note->recipient_company,
                'phone' => $note->recipient_phone,
                'address' => $note->delivery_address,
            ],
            'lines' => $note->lines->map(fn ($line) => [
                'description' => $line->description,
                'variant' => $line->variant_name,
                'sku' => $line->sku,
                'reference' => $line->reference,
                'unit' => $line->unit_label,
                'quantity' => $this->format->decimal($line->quantity),
            ])->all(),
            'template_version' => $note->template_version,
            'metadata' => [
                'issued_at' => $note->issued_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                'issued_by' => $note->issuedBy?->name,
            ],
        ];
    }

    public function html(DeliveryNote $note): string
    {
        $payload = $this->payload($note);

        return view($this->templates->deliveryNoteView($note->template_version), $payload)->render();
    }
}
