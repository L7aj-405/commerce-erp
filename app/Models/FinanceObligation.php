<?php

namespace App\Models;

use App\Enums\FinanceObligationCategory;
use App\Enums\PaymentMethod;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceObligation extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'category' => FinanceObligationCategory::class,
            'planned_amount' => 'decimal:4',
            'planned_payment_method' => PaymentMethod::class,
            'due_date' => 'date',
            'recurring_period' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function preferredFinancialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'preferred_financial_account_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
    public function allocations(): HasMany { return $this->hasMany(FinanceOutgoingPaymentAllocation::class, 'obligation_id'); }
    public function recurringTemplate(): BelongsTo { return $this->belongsTo(FinanceRecurringObligationTemplate::class, 'recurring_template_id'); }
    public function supplierInstallmentPlan(): BelongsTo { return $this->belongsTo(FinanceSupplierInstallmentPlan::class, 'supplier_installment_plan_id'); }
    public function procurement(): BelongsTo { return $this->belongsTo(SalesOrderProcurement::class, 'sales_order_procurement_id'); }
    public function paymentInstruments(): HasMany { return $this->hasMany(FinancePaymentInstrument::class, 'obligation_id'); }
}
