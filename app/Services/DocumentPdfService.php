<?php

namespace App\Services;

use App\Contracts\PdfGenerator;
use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Illuminate\Validation\ValidationException;

class DocumentPdfService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly InvoiceDocumentRenderer $invoices,
        private readonly DeliveryNoteDocumentRenderer $deliveryNotes,
    ) {}

    /** @return array{bytes:string, filename:string, mime:string} */
    public function invoice(Invoice $invoice): array
    {
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw ValidationException::withMessages(['invoice' => 'Only an issued Invoice has an official PDF.']);
        }

        return ['bytes' => $this->pdf->generate($this->invoices->html($invoice)), 'filename' => $this->filename('Facture', $invoice->invoice_number), 'mime' => 'application/pdf'];
    }

    /** @return array{bytes:string, filename:string, mime:string} */
    public function deliveryNote(DeliveryNote $note): array
    {
        if ($note->status !== DeliveryNoteStatus::Issued) {
            throw ValidationException::withMessages(['delivery_note' => 'Only an issued Delivery Note has an official PDF.']);
        }

        return ['bytes' => $this->pdf->generate($this->deliveryNotes->html($note)), 'filename' => $this->filename('Bon-de-Livraison', $note->delivery_note_number), 'mime' => 'application/pdf'];
    }

    private function filename(string $prefix, ?string $number): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $number) ?: 'document';

        return "{$prefix}-{$safe}.pdf";
    }
}
