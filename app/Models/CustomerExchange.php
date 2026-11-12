<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveStore;
use App\Models\Concerns\ScopesToSandbox;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerExchange extends Model
{
    use ScopesToActiveStore;
    use ScopesToSandbox;

    protected $guarded = ['*'];

    public function sandboxLinks(): array
    {
        return ['sales_order_id' => 'sales_orders', 'customer_return_id' => 'customer_returns'];
    }

    public function sandboxStatuses(): ?array
    {
        return null;
    }

    public function isTest(): bool
    {
        return $this->sandbox_id !== null;
    }

    /** Table of the exchange <-> payment pivot of the current environment (Test payments live in the Test ledger). */
    public static function paymentPivotTable(): string
    {
        return app(SandboxContext::class)->isSandbox() ? 'sandbox_customer_exchange_payments' : 'customer_exchange_payments';
    }

    protected $hidden = ['client_operation_id', 'operation_hash'];

    protected function casts(): array
    {
        return [
            'replacement_items' => 'array',
            'returned_total' => 'decimal:4',
            'new_items_total' => 'decimal:4',
            'difference_amount' => 'decimal:4',
            'return_received_at' => 'datetime',
            'replacement_fulfilled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function customerReturn(): BelongsTo { return $this->belongsTo(CustomerReturn::class); }
    public function salesOrderAddendum(): BelongsTo { return $this->belongsTo(SalesOrderAddendum::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function completedBy(): BelongsTo { return $this->belongsTo(User::class, 'completed_by_user_id'); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }

    public function payments(): BelongsToMany
    {
        $relation = $this->belongsToMany(Payment::class, self::paymentPivotTable())->withTimestamps();

        return app(SandboxContext::class)->isSandbox() ? $relation->withPivot('sandbox_id', 'organization_id') : $relation;
    }
}
