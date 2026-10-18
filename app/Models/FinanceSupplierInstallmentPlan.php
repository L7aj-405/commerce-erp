<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceSupplierInstallmentPlan extends Model
{
    use ScopesToActiveOrganization;
    protected $guarded = ['*'];
    protected function casts(): array { return ['agreed_total' => 'decimal:4']; }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function procurement(): BelongsTo { return $this->belongsTo(SalesOrderProcurement::class, 'sales_order_procurement_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function obligations(): HasMany { return $this->hasMany(FinanceObligation::class, 'supplier_installment_plan_id'); }
}
