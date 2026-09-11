<?php

namespace App\Models;

use App\Enums\SalesOrderFulfillmentStatus;
use App\Enums\SalesOrderPaymentStatus;
use App\Enums\SalesOrderSource;
use App\Enums\SalesOrderStatus;
use App\Models\Concerns\ScopesToActiveStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use ScopesToActiveStore;

    protected $guarded = ['*'];

    protected $hidden = ['client_operation_id', 'pos_checkout_hash'];

    protected function casts(): array
    {
        return [
            'source' => SalesOrderSource::class,
            'status' => SalesOrderStatus::class,
            'fulfillment_status' => SalesOrderFulfillmentStatus::class,
            'payment_status' => SalesOrderPaymentStatus::class,
            'ordered_at' => 'datetime',
            'sale_date' => 'date',
            'subtotal_excl_tax' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total_incl_tax' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'pos_global_discount_value' => 'decimal:4',
            'pos_shipping_fee' => 'decimal:4',
            'pos_held_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function posWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'pos_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('position');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(TransferRequest::class);
    }

    public function procurements(): HasMany
    {
        return $this->hasMany(SalesOrderProcurement::class);
    }

    /**
     * True while a linked internal Transfer Request still has to bring remote
     * stock to the operational warehouse before final Showroom fulfilment.
     */
    public function awaitingReplenishment(): bool
    {
        return $this->transferRequests()
            ->whereIn('status', ['requested', 'preparing', 'shipped'])
            ->whereHas('lines', fn ($query) => $query->where('reason', 'order_fulfillment'))
            ->exists();
    }

    /**
     * True while a valid supplier special-order this Order depends on has not
     * yet physically arrived (still awaiting confirmation, or confirmed/ordered
     * but not received). The Order is not ready for customer fulfilment.
     */
    public function awaitingSupplierProcurement(): bool
    {
        return $this->procurements()
            ->whereIn('status', ['pending_supplier', 'supplier_confirmed', 'ordered'])
            ->exists();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by_user_id');
    }
}
