<?php

namespace App\Models;

use App\Models\Concerns\RoutesToSandboxInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAllocation extends Model
{
    use RoutesToSandboxInventory;

    /** Phase 5: only ever linked to an order of the same environment. */
    public function sandboxSalesOrderColumn(): ?string
    {
        return 'sales_order_id';
    }

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function expectedCollectionMatches(): HasMany
    {
        return $this->hasMany(FinanceExpectedCollectionMatch::class);
    }
}
