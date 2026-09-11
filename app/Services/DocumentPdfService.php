<?php

namespace App\Services;

use App\Contracts\PdfGenerator;
use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\TransferRequest;
use Illuminate\Validation\ValidationException;

class DocumentPdfService
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly InvoiceDocumentRenderer $invoices,
        private readonly DeliveryNoteDocumentRenderer $deliveryNotes,
        private readonly QuotationDocumentRenderer $quotations,
        private readonly TransferRequestDocumentRenderer $transferRequests,
    ) {}

    /**
     * The optional Bon de sortie for an internal Transfer Request. Purely a
     * printable warehouse justificatif — no inventory or finance effect, no
     * status guard here (the controller only offers it from `preparing`
     * onward). Reprintable at any time.
     *
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function transferRequestBonDeSortie(TransferRequest $request): array
    {
        return [
            'bytes' => $this->pdf->generate($this->transferRequests->html($request)),
            'filename' => $this->filename('Bon-de-sortie', $request->request_number),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * Render a Devis through the real Dompdf document pipeline.
     *
     * A draft renders too — as a genuine A4 BROUILLON PDF with no official
     * number and no issue metadata — so the "Aperçu PDF" preview and the issued
     * "Voir PDF" come out of the exact same rendering architecture. Callers that
     * must refuse a draft (public shared link, email delivery) guard it
     * themselves.
     *
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function quotation(Quotation $quotation): array
    {
        return [
            'bytes' => $this->pdf->generate($this->quotations->html($quotation), ['pageNumbers' => true]),
            'filename' => $this->filename('Devis', $quotation->quotation_number ?? 'brouillon'),
            'mime' => 'application/pdf',
        ];
    }

    /** @return array{bytes:string, filename:string, mime:string} */
    public function invoice(Invoice $invoice): array
    {
        // An issued Invoice has an official PDF; a superseded one keeps its own
        // (it stays a real historical document after a correction replaces it).
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Superseded], true)) {
            throw ValidationException::withMessages(['invoice' => 'Only an issued Invoice has an official PDF.']);
        }

        return ['bytes' => $this->pdf->generate($this->invoices->html($invoice), ['pageNumbers' => true]), 'filename' => $this->filename('Facture', $invoice->invoice_number), 'mime' => 'application/pdf'];
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
