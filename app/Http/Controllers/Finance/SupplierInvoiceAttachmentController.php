<?php

namespace App\Http\Controllers\Finance;

use App\Actions\SupplierInvoices\AttachSupplierInvoiceFileAction;
use App\Actions\SupplierInvoices\RemoveSupplierInvoiceAttachmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\UploadSupplierInvoiceAttachmentRequest;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAttachment;
use App\Services\ActiveTenantContext;
use App\Services\Sandbox\SandboxContext;
use App\Services\SupplierInvoices\SupplierInvoiceSettings;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierInvoiceAttachmentController extends Controller implements HasMiddleware
{
    /** Kill switch: the feature answers 404 until enabled (and always in Test Mode). */
    public static function middleware(): array
    {
        return [function (Request $request, Closure $next) {
            abort_if(app(SandboxContext::class)->isSandbox(), 404);
            abort_unless(app(SupplierInvoiceSettings::class)->enabled(), 404);

            return $next($request);
        }];
    }

    public function __construct(private readonly ActiveTenantContext $context) {}

    public function store(UploadSupplierInvoiceAttachmentRequest $request, SupplierInvoice $supplierInvoice, AttachSupplierInvoiceFileAction $action): RedirectResponse
    {
        $result = $action->execute($request->user(), $this->context->organizationOrFail(), $supplierInvoice, $request->file('file'));

        return redirect()->route('finance.supplier-invoices.show', $supplierInvoice)
            ->with('success', 'Pièce jointe ajoutée.')
            ->with('attachment_warnings', $result['warnings']);
    }

    public function destroy(Request $request, SupplierInvoice $supplierInvoice, SupplierInvoiceAttachment $attachment, RemoveSupplierInvoiceAttachmentAction $action): RedirectResponse
    {
        $action->execute($request->user(), $this->context->organizationOrFail(), $supplierInvoice, $attachment);

        return redirect()->route('finance.supplier-invoices.show', $supplierInvoice)->with('success', 'Pièce jointe retirée.');
    }

    /** Authorized stream: no public URL; the invoice and the attachment must belong together in the active organization. */
    public function show(Request $request, SupplierInvoice $supplierInvoice, SupplierInvoiceAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $supplierInvoice);
        abort_unless(
            (int) $attachment->supplier_invoice_id === (int) $supplierInvoice->getKey()
            && (int) $attachment->organization_id === (int) $supplierInvoice->organization_id,
            404,
        );
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->storage_key), 404);

        return $disk->response($attachment->storage_key, $attachment->original_name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], 'inline');
    }
}
