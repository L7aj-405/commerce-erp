<?php

namespace App\Models;

use App\Models\Concerns\RoutesToSandboxInventory;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceExpectedCollection extends Model
{
    use RoutesToSandboxInventory;

    /** Phase 5: only ever linked to an order of the same environment. */
    public function sandboxSalesOrderColumn(): ?string
    {
        return 'sales_order_id';
    }
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'expected_amount' => 'decimal:4',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function matches(): HasMany { return $this->hasMany(FinanceExpectedCollectionMatch::class, 'expected_collection_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
}
