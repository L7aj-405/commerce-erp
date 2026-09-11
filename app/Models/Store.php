<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

#[Fillable(['name', 'code', 'settings', 'default_tax_rate_id'])]
class Store extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function defaultTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'default_tax_rate_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(StoreMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_memberships')
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query
                ->where('organization_id', Auth::user()->active_organization_id)
                ->whereHas('organization', fn ($query) => $query
                    ->where('status', 'active')
                    ->whereHas('memberships', fn ($query) => $query
                        ->where('user_id', Auth::id())
                        ->where('status', 'active')))
                ->whereHas('memberships', fn ($query) => $query->where('user_id', Auth::id())));
    }
}
