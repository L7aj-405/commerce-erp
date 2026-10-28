<?php

namespace App\Services\Mcp\Documents;

use App\Services\Mcp\McpExecutionContext;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP company stamp registration. Listed only when the connection holds
 * documents.stamp AND the user currently holds the ERP permission; every call
 * re-checks both.
 */
final class DocumentStampToolRegistrar
{
    public function __construct(
        private readonly McpExecutionContext $context,
        private readonly DocumentStampTools $tools,
    ) {}

    /** @param \Mcp\Server\Builder $builder @param array<string, mixed> $outputSchema */
    public function register($builder, ToolAnnotations $readOnly, array $outputSchema): void
    {
        if (! $this->context->allows('documents.stamp')) {
            return;
        }

        $document = [
            'document_type' => ['type' => 'string', 'enum' => McpDocumentStampService::TYPES],
            'document_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The invoice_id or quotation_id.'],
        ];
        $schema = fn (array $properties, array $required) => ['type' => 'object', 'required' => $required, 'properties' => $properties, 'additionalProperties' => false];

        $builder->addTool([$this->tools, 'previewDocumentStamp'], name: 'preview_document_stamp', title: 'Preview company stamp',
            description: 'Checks, without changing anything, whether the company stamp (cachet) can be apposed on an official document: an ISSUED invoice, or the current issued/accepted quotation version. Reports whether a company stamp is configured and whether the document is already stamped, with issues (already_stamped, stamp_not_configured, stamp_file_unavailable, document_not_eligible, forbidden) and a short-lived single-use preview_fingerprint. Generating a PDF (include_company_stamp) never stamps; only apply_document_stamp does.',
            annotations: $readOnly, outputSchema: $outputSchema, inputSchema: $schema($document, ['document_type', 'document_id']));
        $builder->addTool([$this->tools, 'applyDocumentStamp'], name: 'apply_document_stamp', title: 'Apply company stamp',
            description: 'Apposes the company stamp on the previewed document ("Apposer le cachet"), permanently: the document contents, number and status do not change, and its PDF then shows the stamp. A document is stamped at most once (MCP_DOCUMENT_ALREADY_STAMPED otherwise). Requires the preview fingerprint and an idempotency key.',
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false), outputSchema: $outputSchema,
            inputSchema: $schema([...$document, 'preview_fingerprint' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'],
                'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => 'Reuse the same key when retrying: the operation happens once.']],
                ['document_type', 'document_id', 'preview_fingerprint', 'idempotency_key']));
    }
}
