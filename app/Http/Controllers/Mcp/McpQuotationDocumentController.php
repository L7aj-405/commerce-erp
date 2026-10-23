<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Services\AuditLogger;
use App\Services\Mcp\McpConnectionGate;
use App\Services\Mcp\McpExecutionContext;
use App\Services\Mcp\Sales\McpQuotationDocumentService;
use App\Services\Mcp\Sales\QuotationReadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short-lived signed PDF link handed to MCP clients by get_quotation_document.
 * The signature binds quotation + connection + expiry; on every use the
 * connection, organization policy, membership, scope, ERP permission and
 * store access are re-checked exactly as for an MCP tool call.
 */
final class McpQuotationDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        int $quotation,
        McpExecutionContext $context,
        McpConnectionGate $gate,
        QuotationReadService $quotations,
        McpQuotationDocumentService $documents,
        AuditLogger $audit,
    ): Response {
        $connection = McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->find((string) $request->query('connection'));
        abort_unless($connection && $gate->failure($connection) === null, 403);

        $context->reset();
        $context->bind($connection);
        abort_unless($context->allows('quotations.view'), 403);

        $model = $quotations->find($connection->organization, $quotation);
        try {
            $document = $documents->render($model);
        } catch (\InvalidArgumentException) {
            abort(503, 'Document rendering is temporarily unavailable.');
        }
        $audit->record('mcp.quotation_document.downloaded', $connection->user, $connection->organization, $model->store, $model, newValues: [
            'quotation_id' => $model->getKey(), 'filename' => $document['filename'],
        ]);

        return response($document['bytes'], 200, [
            'Content-Type' => $document['mime'],
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
