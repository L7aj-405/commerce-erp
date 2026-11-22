<?php

namespace App\Actions\SupplierInvoices;

use App\Actions\SupplierInvoices\Concerns\AuthorizesSupplierInvoiceAction;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Organization;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Removes an attachment of a DRAFT invoice (row + stored file). Approved and void invoices keep their documents. */
class RemoveSupplierInvoiceAttachmentAction
{
    use AuthorizesSupplierInvoiceAction;

    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Organization $organization, SupplierInvoice $invoice, SupplierInvoiceAttachment $attachment): void
    {
        $this->assertInvoiceAccess($actor, $organization, 'supplier_invoices.manage');
        if ((int) $invoice->organization_id !== (int) $organization->getKey()
            || (int) $attachment->organization_id !== (int) $organization->getKey()
            || (int) $attachment->supplier_invoice_id !== (int) $invoice->getKey()) {
            abort(404);
        }
        $this->assertStoreAccess($actor, $invoice->store, 'canEdit');

        $file = DB::transaction(function () use ($actor, $organization, $invoice, $attachment) {
            $locked = SupplierInvoice::query()->where('organization_id', $organization->getKey())->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== SupplierInvoiceStatus::Draft) {
                throw ValidationException::withMessages(['attachment' => 'Seules les pièces jointes d’un brouillon peuvent être retirées.']);
            }
            $row = SupplierInvoiceAttachment::query()->where('organization_id', $organization->getKey())
                ->where('supplier_invoice_id', $locked->getKey())->whereKey($attachment->getKey())->firstOrFail();
            $row->delete();
            $this->audit->record('supplier_invoice.attachment_removed', $actor, $organization, $locked->store, $locked, oldValues: [
                'attachment_id' => $row->getKey(), 'file_name' => $row->original_name, 'mime' => $row->mime, 'sha256' => $row->sha256,
            ]);

            return [$row->disk, $row->storage_key];
        }, 3);

        // Only after the row is gone: a failed delete never leaves a row pointing at a missing file.
        Storage::disk($file[0])->delete($file[1]);
    }
}
