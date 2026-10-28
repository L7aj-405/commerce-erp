<?php

namespace App\Services\Mcp\Documents;

use App\Actions\Documents\StampInvoiceAction;
use App\Actions\Quotations\StampQuotationAction;
use App\Models\DocumentStampApposition;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Services\Mcp\Finance\InvoiceReadService;
use App\Services\Mcp\McpDryRun;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Parties\McpPartyPreviews;
use App\Services\Mcp\Sales\QuotationReadService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * "Apposer le cachet" over MCP. The apposition is always made by the canonical
 * StampInvoiceAction / StampQuotationAction (eligibility, documents.stamp +
 * store, DocumentStampApposer freezing the active company stamp, one apposition
 * per document, invoice.stamped / quotation.stamped audit). The preview runs
 * that same action in a rolled-back transaction. Stamping never changes the
 * document's contents, number or status, and generating a PDF never stamps.
 */
final class McpDocumentStampService
{
    /** Idempotency target type (mcp_idempotency_records.target_type is varchar(32)). */
    public const TARGET_TYPE = 'stamp_apposition';

    public const TYPES = ['invoice', 'quotation'];

    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly InvoiceReadService $invoices,
        private readonly QuotationReadService $quotations,
        private readonly StampInvoiceAction $stampInvoice,
        private readonly StampQuotationAction $stampQuotation,
        private readonly McpPartyPreviews $previews,
        private readonly McpDryRun $dryRun,
    ) {}

    /** @return array{document_id: int, document_type: string} */
    public function normalize(string $documentType, int $documentId): array
    {
        $payload = ['document_type' => trim($documentType), 'document_id' => $documentId];
        Validator::make($payload, [
            'document_type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'document_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        ksort($payload);

        return $payload;
    }

    /** @param array{document_id: int, document_type: string} $payload @return array<string, mixed> */
    public function preview(array $payload): array
    {
        $result = $this->dryRun->run(function () use ($payload) {
            $document = $this->find($payload);
            $state = $this->stateHash($document);
            $existing = $document->stampApposition()->first();
            $issues = [];
            if ($existing) {
                $issues[] = ['path' => 'document', 'code' => 'already_stamped', 'message' => 'This document already carries the company stamp; it is never stamped twice.',
                    'details' => ['applied_at' => $existing->applied_at?->toIso8601String()]];
            } else {
                try {
                    $this->stamp($document);
                } catch (ValidationException $exception) {
                    $issues[] = $this->issue($exception);
                } catch (HttpExceptionInterface) {
                    $issues[] = ['path' => 'document', 'code' => 'forbidden', 'message' => 'The ERP refused to stamp this document for the connection user (documents.stamp permission or store membership).'];
                }
            }

            return ['document' => $document, 'state' => $state, 'existing' => $existing, 'issues' => $issues,
                'stamp' => $document->organization->activeDocumentStamp()->first(['id', 'updated_at'])];
        });

        [$fingerprint, $expiresAt] = $result['issues'] === []
            ? $this->previews->issue('document_stamp', $this->binding($payload, $result['state']))
            : [null, null];

        return [
            'document' => $this->summary($result['document']),
            'company_stamp' => ['configured' => $result['stamp'] !== null],
            'already_stamped' => $result['existing'] !== null,
            'stamp' => $result['existing'] ? $this->apposition($result['existing']) : null,
            'valid' => $result['issues'] === [],
            'issues' => $result['issues'],
            'required_scopes' => ['documents.stamp'],
            'preview_fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'note' => 'Applying apposes the company stamp configured now on this official document, permanently (it cannot be removed through MCP). The document contents, number and status do not change; its PDF then always shows the stamp.',
        ];
    }

    /**
     * Must run inside the caller's transaction (idempotency wrapper).
     *
     * @param array{document_id: int, document_type: string} $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload, string $fingerprint): array
    {
        $document = $this->find($payload, lock: true);
        if ($existing = $document->stampApposition()->first()) {
            throw new McpDocumentAlreadyStamped($payload['document_type'], (int) $document->getKey(), $existing->applied_at?->toIso8601String());
        }
        $binding = $this->binding($payload, $this->stateHash($document));
        $this->previews->consume('document_stamp', $fingerprint, $binding);

        try {
            $apposition = $this->stamp($document);
        } catch (Throwable $exception) {
            $this->previews->release('document_stamp', $fingerprint, $binding);
            throw $exception;
        }

        return [
            'target' => ['type' => self::TARGET_TYPE, 'id' => (int) $apposition->getKey()],
            'document' => $this->summary($document->fresh()),
            'company_stamp_applied' => true,
            'stamp' => $this->apposition($apposition),
            'note' => 'The stamp is apposed. get_invoice_document / get_quotation_document now render it (company_stamp_applied = true).',
        ];
    }

    private function stamp(Invoice|Quotation $document): DocumentStampApposition
    {
        return $document instanceof Invoice
            ? $this->stampInvoice->execute($this->context->user(), $document)
            : $this->stampQuotation->execute($this->context->user(), $document);
    }

    /** Organisation + accessible-store scoped (the read services); anything else is "not found". */
    private function find(array $payload, bool $lock = false): Invoice|Quotation
    {
        if ($payload['document_type'] === 'invoice') {
            $invoice = $this->invoices->find($this->context->organization(), $payload['document_id']);

            return $lock ? Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->with(['organization', 'store'])->firstOrFail() : $invoice->loadMissing(['organization', 'store']);
        }

        return $this->quotations->find($this->context->organization(), $payload['document_id'], $lock)->loadMissing(['organization', 'store']);
    }

    /** @return array<string, mixed> */
    private function issue(ValidationException $exception): array
    {
        $message = mb_substr((string) (collect($exception->errors())->flatten()->first() ?? 'Rejected.'), 0, 300);

        return match (true) {
            str_contains($message, 'déjà cacheté') => ['path' => 'document', 'code' => 'already_stamped', 'message' => $message],
            str_contains($message, 'Aucun cachet configuré') => ['path' => 'company_stamp', 'code' => 'stamp_not_configured', 'message' => $message],
            str_contains($message, 'indisponible') => ['path' => 'company_stamp', 'code' => 'stamp_file_unavailable', 'message' => $message],
            str_contains($message, 'Seule une facture émise') || str_contains($message, 'Seul un devis') => ['path' => 'document', 'code' => 'document_not_eligible', 'message' => $message],
            default => ['path' => 'company_stamp', 'code' => 'stamp_rejected', 'message' => $message],
        };
    }

    /** @return array<string, mixed> */
    private function summary(Invoice|Quotation $document): array
    {
        return $document instanceof Invoice
            ? ['type' => 'invoice', 'id' => (int) $document->getKey(), 'number' => $document->invoice_number, 'status' => $document->status->value]
            : ['type' => 'quotation', 'id' => (int) $document->getKey(), 'number' => $document->quotation_number, 'status' => $document->status->value];
    }

    /** @return array<string, mixed> */
    private function apposition(DocumentStampApposition $apposition): array
    {
        return ['id' => (int) $apposition->getKey(), 'applied_at' => $apposition->applied_at?->toIso8601String(), 'applied_by_user_id' => $apposition->applied_by_user_id];
    }

    /** The document row + whether it is stamped + the active company stamp: what the apposition depends on. */
    private function stateHash(Invoice|Quotation $document): string
    {
        $stamp = $document->organization->activeDocumentStamp()->first(['id', 'updated_at']);

        return hash('sha256', implode('|', [
            $document::class, $document->getKey(), $document->status->value, $document->updated_at?->format('Y-m-d H:i:s.u'),
            $document->stampApposition()->exists() ? 'stamped' : 'unstamped',
            $stamp ? $stamp->id.':'.$stamp->updated_at?->format('Y-m-d H:i:s.u') : 'none',
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function binding(array $payload, string $stateHash): array
    {
        return [
            'entity' => 'document_stamp',
            'mode' => 'apply',
            'target_id' => $payload['document_id'],
            'payload_hash' => $this->previews->hash($payload),
            'state_hash' => $stateHash,
            'required_scopes' => ['documents.stamp'],
        ];
    }
}
