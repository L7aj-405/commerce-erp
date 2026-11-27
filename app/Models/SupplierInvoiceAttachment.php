<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Stored scan/photo of a supplier invoice (metadata only; the file is on a private disk). May be added at any status. */
class SupplierInvoiceAttachment extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // Removal is only possible while the invoice is still a draft.
        static::deleting(function (self $attachment): void {
            if (! SupplierInvoice::isDraftRow((int) $attachment->supplier_invoice_id)) {
                throw new LogicException('Attachments of an approved or void supplier invoice cannot be removed.');
            }
        });
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
