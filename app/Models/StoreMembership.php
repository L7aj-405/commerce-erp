<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class StoreMembership extends Model
{
    use HasFactory;

    protected $guarded = ['*'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
                ->whereHas('store.memberships', fn ($query) => $query->where('user_id', Auth::id())));
    }
}
