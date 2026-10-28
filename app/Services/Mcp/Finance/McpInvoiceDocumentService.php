<?php

namespace App\Services\Mcp\Finance;

use App\Models\Invoice;
use App\Services\DocumentPdfService;
use App\Services\DocumentStampRenderer;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Invoice PDF for MCP from the ERP's own DocumentPdfService (official PDF of
 * an issued or superseded invoice only) and DocumentStampRenderer (an
 * existing stamp apposition). Read-only: generating the PDF changes nothing.
 */
final class McpInvoiceDocumentService
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly InvoiceReadService $invoices,
        private readonly DocumentPdfService $pdf,
        private readonly DocumentStampRenderer $stamps,
    ) {}

    /** @return array<string, mixed> */
    public function document(int $invoiceId, bool $includeStamp): array
    {
        $invoice = $this->invoices->find($this->context->organization(), $invoiceId);
        $document = $this->render($invoice);
        $applied = $this->stamps->isRenderable($invoice->stampApposition()->first());
        $warnings = [];
        if ($includeStamp && ! $applied) {
            $warnings[] = ['code' => 'stamp_not_apposed', 'message' => 'No company stamp is apposed on this invoice. The ERP applies the stamp as an explicit, persistent action (Apposer le cachet); the document was generated without it.'];
        }
        if (! $includeStamp && $applied) {
            $warnings[] = ['code' => 'stamp_already_apposed', 'message' => 'This invoice already carries an apposed company stamp; the ERP document always includes it.'];
        }
        $expiresAt = now()->addSeconds((int) config('mcp.documents.link_ttl_seconds', 600));

        return [
            'invoice_id' => $invoice->getKey(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'filename' => $document['filename'],
            'mime_type' => $document['mime'],
            'size_bytes' => strlen($document['bytes']),
            'sha256' => hash('sha256', $document['bytes']),
            'generated_at' => now()->toIso8601String(),
            'company_stamp_applied' => $applied,
            'warnings' => $warnings,
            // Short-lived signed link bound to this invoice and MCP connection;
            // re-checked against the connection, scope and permission on use.
            'download_url' => URL::temporarySignedRoute('mcp.documents.invoice', $expiresAt, [
                'invoice' => $invoice->getKey(),
                'connection' => $this->context->connectionOrFail()->getKey(),
            ]),
            'download_expires_at' => $expiresAt->toIso8601String(),
            'version' => $this->invoices->version($invoice),
        ];
    }

    /** @return array{bytes: string, filename: string, mime: string} */
    public function render(Invoice $invoice): array
    {
        try {
            return $this->pdf->invoice($invoice);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw new InvalidArgumentException('document_unavailable');
        }
    }
}
