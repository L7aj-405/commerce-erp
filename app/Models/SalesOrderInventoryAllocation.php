<?php

namespace App\Models;

use App\Models\Concerns\RoutesToSandboxInventory;
use App\Services\Sandbox\SandboxContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SalesOrderInventoryAllocation extends Model
{
    use RoutesToSandboxInventory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        // Phase 4: an allocation belongs to the environment of its order line.
        // A Test allocation can never hold a Production order line (or the
        // reverse); the reservation side is enforced by the composite FKs.
        static::saving(function (SalesOrderInventoryAllocation $allocation) {
            if (! $allocation->isDirty('sales_order_line_id') && $allocation->exists) {
                return;
            }
            $orderSandbox = DB::table('sales_order_lines')
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
                ->where('sales_order_lines.id', $allocation->sales_order_line_id)
                ->value('sales_orders.sandbox_id');
            $expected = $allocation->isSandboxInventory() ? app(SandboxContext::class)->id() : null;
            abort_unless((string) $orderSandbox === (string) $expected, 404);
        });
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function inventoryReservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class);
    }
}
