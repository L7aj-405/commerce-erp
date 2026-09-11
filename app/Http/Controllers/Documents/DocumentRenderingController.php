<?php

namespace App\Http\Controllers\Documents;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\DeliveryNoteDocumentRenderer;
use App\Services\DocumentPdfService;
use App\Services\InvoiceDocumentRenderer;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentRenderingController extends Controller
{
    public function printInvoice(Invoice $invoice, InvoiceDocumentRenderer $renderer): Response
    {
        $this->authorize('view', $invoice);

        return response($renderer->html($invoice), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function invoicePdf(Invoice $invoice, DocumentPdfService $documents): Response
    {
        $this->authorize('view', $invoice);

        return $this->pdf($this->generate(fn () => $documents->invoice($invoice)), false);
    }

    public function downloadInvoice(Invoice $invoice, DocumentPdfService $documents): Response
    {
        $this->authorize('view', $invoice);

        return $this->pdf($this->generate(fn () => $documents->invoice($invoice)), true);
    }

    /**
     * Read-only PDF for an issued Invoice, reached through a temporary signed
     * link (e.g. shared over WhatsApp). Authorisation is the URL signature — it
     * is bound to this one invoice id and expires — so no session or policy
     * check applies here. A tampered id or an expired link fails signature
     * validation (403) before this method runs.
     */
    public function sharedInvoicePdf(Invoice $invoice, DocumentPdfService $documents): Response
    {
        // A superseded original is still a real historical document, so an
        // already-shared link keeps working; only drafts have no official PDF.
        abort_unless(in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Superseded], true), 404);

        return $this->pdf($this->generate(fn () => $documents->invoice($invoice)), false);
    }

    public function printDeliveryNote(DeliveryNote $deliveryNote, DeliveryNoteDocumentRenderer $renderer): Response
    {
        $this->authorize('view', $deliveryNote);

        return response($renderer->html($deliveryNote), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function deliveryNotePdf(DeliveryNote $deliveryNote, DocumentPdfService $documents): Response
    {
        $this->authorize('view', $deliveryNote);

        return $this->pdf($this->generate(fn () => $documents->deliveryNote($deliveryNote)), false);
    }

    public function downloadDeliveryNote(DeliveryNote $deliveryNote, DocumentPdfService $documents): Response
    {
        $this->authorize('view', $deliveryNote);

        return $this->pdf($this->generate(fn () => $documents->deliveryNote($deliveryNote)), true);
    }

    /** @param array{bytes:string, filename:string, mime:string} $document */
    private function pdf(array $document, bool $download): Response
    {
        $disposition = $download ? 'attachment' : 'inline';

        return response($document['bytes'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => $disposition.'; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  callable(): array{bytes:string, filename:string, mime:string}  $generator
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function generate(callable $generator): array
    {
        try {
            return $generator();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            abort(503, 'Document rendering is temporarily unavailable.');
        }
    }
}
