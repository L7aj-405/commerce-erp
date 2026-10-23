<?php

namespace App\Services\Mcp\Sales;

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

/** Quotation PDF document + email preview/send tools (MCP-8). */
final class QuotationDocumentTools
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly McpQuotationDocumentService $documents,
        private readonly McpIdempotencyService $idempotency,
        private readonly AuditLogger $audit,
    ) {}

    public function getQuotationDocument(int $quotation_id, string $format = 'pdf', bool $include_company_stamp = false): CallToolResult
    {
        return $this->execute('get_quotation_document', function () use ($quotation_id, $format, $include_company_stamp) {
            $this->context->authorize('quotations.view');
            if ($format !== 'pdf' || $quotation_id < 1) throw new InvalidArgumentException('invalid_arguments');
            $result = $this->documents->document($quotation_id, $include_company_stamp);
            $this->audit->record(event: 'mcp.quotation_document.generated', actor: $this->context->user(), organization: $this->context->organization(), newValues: [
                'quotation_id' => $quotation_id, 'status' => $result['status'], 'filename' => $result['filename'],
                'company_stamp_applied' => $result['company_stamp_applied'],
            ]);

            return $result;
        });
    }

    public function previewQuotationEmail(
        int $quotation_id,
        string $recipient_mode = 'customer',
        ?string $recipient_email = null,
        ?string $subject = null,
        ?string $message = null,
        bool $include_company_stamp = false,
    ): CallToolResult {
        return $this->execute('preview_quotation_email', function () use ($quotation_id, $recipient_mode, $recipient_email, $subject, $message, $include_company_stamp) {
            $this->context->authorize(McpQuotationDocumentService::EMAIL_SCOPE);
            if ($quotation_id < 1) throw new InvalidArgumentException('invalid_id');
            $preview = $this->documents->previewEmail($quotation_id, $recipient_mode, $recipient_email, $subject, $message, $include_company_stamp);
            // Compact: recipient domain only, never the body.
            $this->audit->record(event: 'mcp.quotation_email.previewed', actor: $this->context->user(), organization: $this->context->organization(), newValues: [
                'quotation_id' => $quotation_id, 'recipient_mode' => $recipient_mode,
                'recipient_domain' => isset($preview['recipient']['email']) ? substr(strrchr($preview['recipient']['email'], '@') ?: '', 1) : null,
                'custom_subject' => $subject !== null, 'custom_message' => $message !== null,
                'valid' => $preview['valid'], 'issue_codes' => array_values(array_unique(array_column($preview['issues'], 'code'))),
            ]);

            return $preview;
        });
    }

    public function sendQuotationEmail(int $quotation_id, string $preview_fingerprint, string $idempotency_key): CallToolResult
    {
        return $this->execute('send_quotation_email', function () use ($quotation_id, $preview_fingerprint, $idempotency_key) {
            $this->context->authorize(McpQuotationDocumentService::EMAIL_SCOPE);
            if ($quotation_id < 1) throw new InvalidArgumentException('invalid_id');

            // Not transactional: the ERP's sent/failed delivery + audit must persist.
            // The outcome (sent or failed) is stored, so a retry never re-sends.
            $result = $this->idempotency->executeExternal('send_quotation_email', $idempotency_key,
                ['quotation_id' => $quotation_id, 'preview_fingerprint' => $preview_fingerprint],
                fn () => [...$this->documents->send($quotation_id, $preview_fingerprint), 'target' => ['type' => 'quotation', 'id' => $quotation_id]],
            );
            if (! $result['sent']) {
                throw new McpEmailNotSent($result);
            }

            // The canonical `quotation.sent` audit (MCP-attributed) is written by the ERP action.
            return $result;
        });
    }

    /** @param callable(): array<string, mixed> $callback */
    private function execute(string $tool, callable $callback): CallToolResult
    {
        try {
            return $this->success($callback());
        } catch (AuthorizationException) {
            return $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 404
                ? $this->error('MCP_QUOTATION_NOT_FOUND', 'The requested resource was not found.')
                : $this->error('MCP_FORBIDDEN', 'The connection is not authorized to use this capability.');
        } catch (ModelNotFoundException) {
            return $this->error('MCP_QUOTATION_NOT_FOUND', 'The requested resource was not found.');
        } catch (McpEmailNotSent $exception) {
            return $this->error('MCP_EMAIL_NOT_SENT', 'The email was not sent. It will not be retried automatically; preview again before any new attempt.', $exception->result);
        } catch (ValidationException $exception) {
            return $this->error('MCP_VALIDATION_FAILED', 'The ERP rejected the request.', ['fields' => collect($exception->errors())->take(20)->map(fn ($messages) => array_map(fn ($message) => mb_substr((string) $message, 0, 200), array_slice((array) $messages, 0, 3)))->all()]);
        } catch (LockTimeoutException) {
            return $this->error('MCP_IDEMPOTENCY_BUSY', 'An identical operation is already being processed.');
        } catch (InvalidArgumentException $exception) {
            [$code, $message] = match ($exception->getMessage()) {
                'stale_or_invalid_preview' => ['MCP_PREVIEW_STALE', 'The email preview is missing, expired, already used, or the quotation changed. Preview again.'],
                'idempotency_key_payload_mismatch' => ['MCP_IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with a different request.'],
                'document_unavailable' => ['MCP_DOCUMENT_UNAVAILABLE', 'The quotation document could not be rendered right now.'],
                default => ['MCP_INVALID_ARGUMENTS', 'The supplied tool arguments are invalid.'],
            };

            return $this->error($code, $message);
        } catch (Throwable $exception) {
            Log::warning('MCP quotation document tool failed.', [
                'tool' => $tool,
                'connection_id' => $this->context->connection()?->getKey(),
                'exception_type' => $exception::class,
            ]);

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
