<?php

namespace App\Http\Controllers\Quotations;

use App\Enums\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\DocumentPdfService;
use App\Services\QuotationDocumentRenderer;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuotationRenderingController extends Controller
{
    public function print(Quotation $quotation, QuotationDocumentRenderer $renderer): Response
    {
        $this->authorize('view', $quotation);

        return response($renderer->html($quotation), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function pdf(Quotation $quotation, DocumentPdfService $documents): Response
    {
        $this->authorize('view', $quotation);

        return $this->stream($this->generate(fn () => $documents->quotation($quotation)), false);
    }

    public function download(Quotation $quotation, DocumentPdfService $documents): Response
    {
        $this->authorize('view', $quotation);

        return $this->stream($this->generate(fn () => $documents->quotation($quotation)), true);
    }

    /**
     * Read-only PDF for an issued Devis, reached through a temporary signed link
     * (WhatsApp / email). The URL signature is the authorisation and binds the
     * link to one quotation id + expiry.
     */
    public function shared(Quotation $quotation, DocumentPdfService $documents): Response
    {
        abort_if($quotation->status === QuotationStatus::Draft, 404);

        return $this->stream($this->generate(fn () => $documents->quotation($quotation)), false);
    }

    /** @param array{bytes:string, filename:string, mime:string} $document */
    private function stream(array $document, bool $download): Response
    {
        return response($document['bytes'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$document['filename'].'"',
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
