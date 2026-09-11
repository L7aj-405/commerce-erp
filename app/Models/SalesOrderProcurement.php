<?php

namespace App\Models;

use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supplier special-order requirement for one customer Sales Order line.
 * Represents the SUPPLIER_ORDER-sourced portion of that line; the COMPANY_STOCK
 * portion lives in `sales_order_inventory_allocations`. There is never a second
 * Sales Order line for the procured quantity.
 */
class SalesOrderProcurement extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => SupplierProcurementStatus::class,
            'supplier_availability_status' => SupplierAvailabilityStatus::class,
            'quantity' => 'decimal:4',
            'supplier_unit_cost' => 'decimal:4',
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function receivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'receiving_warehouse_id');
    }

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TransferRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
