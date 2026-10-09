<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class CommissionRuleSet extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'activated_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function tiers(): HasMany { return $this->hasMany(CommissionRuleTier::class)->orderBy('sort_order'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function activatedBy(): BelongsTo { return $this->belongsTo(User::class, 'activated_by_user_id'); }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query->where('organization_id', Auth::user()->active_organization_id));
    }
}
