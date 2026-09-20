<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerExchange extends Model
{
    use ScopesToActiveStore;

    protected $guarded = ['*'];

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
        return $this->belongsToMany(Payment::class, 'customer_exchange_payments')->withTimestamps();
    }
}
