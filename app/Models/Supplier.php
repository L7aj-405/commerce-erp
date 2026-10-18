<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Minimal Organization-scoped supplier directory. Deliberately NOT Accounts
 * Payable — no balances, terms or ledgers. Just enough to name who confirmed a
 * special-order availability and who the goods are ordered from.
 */
class Supplier extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function procurements(): HasMany
    {
        return $this->hasMany(SalesOrderProcurement::class);
    }

    public function financeObligations(): HasMany
    {
        return $this->hasMany(FinanceObligation::class);
    }

    public function financeOutgoingPayments(): HasMany
    {
        return $this->hasMany(FinanceOutgoingPayment::class);
    }

    public function financeRecurringObligationTemplates(): HasMany
    {
        return $this->hasMany(FinanceRecurringObligationTemplate::class);
    }

    public function financeSupplierInstallmentPlans(): HasMany { return $this->hasMany(FinanceSupplierInstallmentPlan::class); }
    public function financePaymentInstruments(): HasMany { return $this->hasMany(FinancePaymentInstrument::class); }
}
