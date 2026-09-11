<?php

namespace App\Models;

use App\Enums\SalesOrderDiscountType;
use App\Enums\SalesOrderLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class SalesOrderLine extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'line_type' => SalesOrderLineType::class,
            'discount_type' => SalesOrderDiscountType::class,
            'quantity' => 'decimal:4',
            'unit_price_excl_tax' => 'decimal:4',
            'unit_price_incl_tax' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_unresolved' => 'boolean',
            'discount_value' => 'decimal:4',
            'subtotal_excl_tax' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'taxable_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total_incl_tax' => 'decimal:4',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SalesOrderInventoryAllocation::class);
    }

    public function procurements(): HasMany
    {
        return $this->hasMany(SalesOrderProcurement::class);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query
                ->where('organization_id', Auth::user()->active_organization_id)
                ->whereHas('salesOrder', fn ($query) => $query->where('store_id', Auth::user()->active_store_id)));
    }
}
