<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpConnection;
use App\Services\AuditLogger;
use App\Services\Mcp\Finance\InvoiceReadService;
use App\Services\Mcp\Finance\McpInvoiceDocumentService;
use App\Services\Mcp\McpConnectionGate;
use App\Services\Mcp\McpExecutionContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short-lived signed PDF link handed to MCP clients by get_invoice_document.
 * The signature binds invoice + connection + expiry; on every use the
 * connection, organization policy, membership, scope, ERP permission and
 * store access are re-checked exactly as for an MCP tool call.
 */
final class McpInvoiceDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        int $invoice,
        McpExecutionContext $context,
        McpConnectionGate $gate,
        InvoiceReadService $invoices,
        McpInvoiceDocumentService $documents,
        AuditLogger $audit,
    ): Response {
        $connection = McpConnection::query()->with(['organization', 'user', 'scopeRecords'])->find((string) $request->query('connection'));
        abort_unless($connection && $gate->failure($connection) === null, 403);

        $context->reset();
        $context->bind($connection);
        abort_unless($context->allows('invoices.view'), 403);

        $model = $invoices->find($connection->organization, $invoice);
        try {
            $document = $documents->render($model);
        } catch (ValidationException) {
            abort(404);
        } catch (\InvalidArgumentException) {
            abort(503, 'Document rendering is temporarily unavailable.');
        }
        $audit->record('mcp.invoice_document.downloaded', $connection->user, $connection->organization, $model->store, $model, newValues: [
            'invoice_id' => $model->getKey(), 'filename' => $document['filename'],
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
