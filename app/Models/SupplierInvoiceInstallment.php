<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One scheduled payment of a supplier invoice. On approval each installment
 * gets exactly one finance obligation; the single-direction link lives on
 * finance_obligations (supplier_invoice_installment_id, added in a later migration).
 */
class SupplierInvoiceInstallment extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $guard = function (self $installment): void {
            $invoiceId = (int) ($installment->getRawOriginal('supplier_invoice_id') ?? $installment->getAttributes()['supplier_invoice_id'] ?? 0);
            if ($invoiceId > 0 && ! SupplierInvoice::isDraftRow($invoiceId)) {
                throw new LogicException('Installments of an approved or void supplier invoice are immutable.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'due_date' => 'date',
            'amount' => 'decimal:4',
            'planned_payment_method' => PaymentMethod::class,
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function preferredFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'preferred_financial_account_id');
    }

    /** Available once the obligation link column exists (PR7); resolves to null until then. */
    public function obligation(): HasOne
    {
        return $this->hasOne(FinanceObligation::class, 'supplier_invoice_installment_id');
    }
}
