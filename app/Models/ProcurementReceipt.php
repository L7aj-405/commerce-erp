<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One physical supplier receipt for a procurement requirement (optionally
 * against one purchase order line): the inbound movement it created and the
 * reservation earmarking the goods for the Sales Order line. Immutable history.
 */
class ProcurementReceipt extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_on' => 'date',
        ];
    }

    public function procurement(): BelongsTo { return $this->belongsTo(SalesOrderProcurement::class, 'sales_order_procurement_id'); }
    public function purchaseOrderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function inventoryMovement(): BelongsTo { return $this->belongsTo(InventoryMovement::class); }
    public function inventoryReservation(): BelongsTo { return $this->belongsTo(InventoryReservation::class); }
    public function allocation(): BelongsTo { return $this->belongsTo(SalesOrderInventoryAllocation::class, 'sales_order_inventory_allocation_id'); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by_user_id'); }
}
