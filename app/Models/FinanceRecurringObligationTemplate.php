<?php

namespace App\Models;

use App\Enums\FinanceObligationCategory;
use App\Enums\FinanceRecurringDueDayMode;
use App\Enums\PaymentMethod;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceRecurringObligationTemplate extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'category' => FinanceObligationCategory::class,
            'planned_amount' => 'decimal:4',
            'planned_payment_method' => PaymentMethod::class,
            'due_day_mode' => FinanceRecurringDueDayMode::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function preferredFinancialAccount(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'preferred_financial_account_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function deactivatedBy(): BelongsTo { return $this->belongsTo(User::class, 'deactivated_by_user_id'); }
    public function obligations(): HasMany { return $this->hasMany(FinanceObligation::class, 'recurring_template_id'); }
}
