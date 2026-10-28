<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierAvailabilityStatus;
use App\Enums\SupplierProcurementStatus;
use App\Models\Concerns\ScopesToActiveOrganization;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'received_quantity' => 'decimal:4',
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

    public function sourceQuotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'source_quotation_id');
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ProcurementReceipt::class)->orderBy('id');
    }

    /** Quantity on non-cancelled purchase orders (decimal string). */
    public function activePurchaseOrderQuantity(bool $lock = false): string
    {
        return self::activePurchaseOrderQuantities($this->organization_id, [(int) $this->getKey()], $lock)[(int) $this->getKey()] ?? '0.0000';
    }

    /**
     * Quantity on non-cancelled purchase orders per requirement id (one query).
     *
     * @param  list<int>  $procurementIds
     * @return array<int, string>
     */
    public static function activePurchaseOrderQuantities(int $organizationId, array $procurementIds, bool $lock = false): array
    {
        if ($procurementIds === []) {
            return [];
        }

        return PurchaseOrderLine::query()
            ->where('organization_id', $organizationId)
            ->whereIn('sales_order_procurement_id', $procurementIds)
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', '!=', PurchaseOrderStatus::Cancelled->value))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['sales_order_procurement_id', 'quantity'])
            ->groupBy('sales_order_procurement_id')
            ->map(fn ($lines) => $lines->reduce(fn (string $sum, PurchaseOrderLine $line) => Decimal::add($sum, (string) $line->quantity), '0.0000'))
            ->mapWithKeys(fn (string $sum, $id) => [(int) $id => $sum])
            ->all();
    }

    public function financeObligations(): HasMany { return $this->hasMany(FinanceObligation::class); }
    public function financeSupplierInstallmentPlans(): HasMany { return $this->hasMany(FinanceSupplierInstallmentPlan::class); }
}
