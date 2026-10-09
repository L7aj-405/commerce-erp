<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class OrganizationMembership extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['default_warehouse_id' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Warehouse pre-selected for this member when opening the POS. A UX
     * preference only — it grants no access and may point at a warehouse
     * that has since become inactive (callers must re-check availability).
     */
    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query->whereHas(
                'organization.memberships',
                fn ($query) => $query
                    ->where('user_id', Auth::id())
                    ->where('status', 'active'),
            ));
    }
}
