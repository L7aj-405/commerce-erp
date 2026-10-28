<?php

namespace App\Services\Mcp\Documents;

use App\Services\AuditLogger;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * MCP "Apposer le cachet" on invoices and quotations. Adapters only: membership
 * + ERP permission + MCP scope, tenant from the connection, store access from
 * the user's memberships; the apposition is the canonical stamp action, which
 * writes invoice.stamped / quotation.stamped with the MCP connection attribution.
 */
final class DocumentStampTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpDocumentStampService $stamps,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function previewDocumentStamp(string $document_type, int $document_id): CallToolResult
    {
        return $this->execute('preview_document_stamp', function () use ($document_type, $document_id) {
            $this->context->authorize('documents.stamp');
            $preview = $this->stamps->preview($this->stamps->normalize($document_type, $document_id));
            $this->audit->record(event: 'mcp.document_stamp.previewed', actor: $this->context->user(), organization: $this->context->organization(),
                newValues: ['document_type' => $document_type, 'document_id' => $document_id, 'valid' => $preview['valid'], 'issue_codes' => array_column($preview['issues'], 'code')]);

            return $preview;
        });
    }

    public function applyDocumentStamp(string $document_type, int $document_id, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('apply_document_stamp', function () use ($document_type, $document_id, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize('documents.stamp');
            $payload = $this->stamps->normalize($document_type, $document_id);

            return $this->idempotency->execute('apply_document_stamp', $idempotency_key, $payload,
                fn () => $this->stamps->apply($payload, $preview_fingerprint));
        });
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (McpDocumentAlreadyStamped $exception) {
            return $this->error('MCP_DOCUMENT_ALREADY_STAMPED', 'This document already carries the company stamp; no second apposition was created.',
                ['document_type' => $exception->documentType, 'document_id' => $exception->documentId, 'applied_at' => $exception->appliedAt]);
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error('MCP_DOCUMENT_NOT_FOUND', 'The document was not found.')
                : $this->error('MCP_FORBIDDEN', 'The ERP refused this action for the connection user (documents.stamp permission or store membership).');
        } catch (ModelNotFoundException) {
            return $this->error('MCP_DOCUMENT_NOT_FOUND', 'The document was not found.');
        } catch (ValidationException $exception) {
            $message = (string) (collect($exception->errors())->flatten()->first() ?? '');
            [$code, $text] = match (true) {
                isset($exception->errors()['document_type']) || isset($exception->errors()['document_id']) => ['MCP_VALIDATION_FAILED', 'document_type must be invoice or quotation, with a positive document_id.'],
                str_contains($message, 'Aucun cachet configuré') || str_contains($message, 'indisponible') => ['MCP_STAMP_NOT_CONFIGURED', mb_substr($message, 0, 200)],
                str_contains($message, 'Seule une facture émise') || str_contains($message, 'Seul un devis') => ['MCP_DOCUMENT_NOT_ELIGIBLE', mb_substr($message, 0, 200)],
                default => ['MCP_VALIDATION_FAILED', mb_substr($message, 0, 200)],
            };

            return $this->error($code, $text);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The preview is missing, expired, consumed, or no longer matches the current data. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP document stamp tool failed.', ['tool' => $tool, 'connection_id' => $this->context->connection()?->getKey(), 'exception_type' => $exception::class]);

            return $this->error('MCP_TOOL_INTERNAL_ERROR', 'The tool could not complete the request.');
        }
    }

    /** @param array<string, mixed> $data */
    private function success(array $data): CallToolResult
    {
        $payload = ['ok' => true, 'data' => $data];

        return new CallToolResult([new TextContent($payload)], structuredContent: $payload);
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, array $details = []): CallToolResult
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) $error['details'] = $details;
        $payload = ['ok' => false, 'error' => $error];

        return new CallToolResult([new TextContent($payload)], isError: true, structuredContent: $payload);
    }
}
