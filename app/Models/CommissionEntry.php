<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use LogicException;

class CommissionEntry extends Model
{
    public const TYPE_SALE = 'sale';
    public const TYPE_RETURN_REVERSAL = 'return_reversal';
    public const TYPE_CORRECTION = 'correction';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (CommissionEntry $entry) {
            $mutable = ['status', 'approved_by_user_id', 'approved_at', 'paid_by_user_id', 'paid_at', 'updated_at'];
            if (array_diff(array_keys($entry->getDirty()), $mutable) !== []) {
                throw new LogicException('Commission calculation snapshots are immutable; append an adjustment entry instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Commission entries are append-only and cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'quantity_snapshot' => 'decimal:4',
            'revenue_ht_snapshot' => 'decimal:4',
            'purchase_cost_snapshot' => 'decimal:4',
            'cost_total_snapshot' => 'decimal:4',
            'margin_amount_snapshot' => 'decimal:4',
            'margin_rate_snapshot' => 'decimal:4',
            'commission_rate_snapshot' => 'decimal:4',
            'commission_amount' => 'decimal:4',
            'rule_min_margin_snapshot' => 'decimal:4',
            'rule_max_margin_snapshot' => 'decimal:4',
            'sale_date' => 'date',
            'occurred_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function salesperson(): BelongsTo { return $this->belongsTo(User::class, 'salesperson_id'); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class)->withoutGlobalScopes(); }
    public function salesOrderLine(): BelongsTo { return $this->belongsTo(SalesOrderLine::class); }
    public function salesOrderRevision(): BelongsTo { return $this->belongsTo(SalesOrderRevision::class); }
    public function customerReturn(): BelongsTo { return $this->belongsTo(CustomerReturn::class)->withoutGlobalScopes(); }
    public function customerReturnLine(): BelongsTo { return $this->belongsTo(CustomerReturnLine::class); }
    public function sourceEntry(): BelongsTo { return $this->belongsTo(self::class, 'source_entry_id'); }
    public function adjustments(): HasMany { return $this->hasMany(self::class, 'source_entry_id'); }
    public function ruleSet(): BelongsTo { return $this->belongsTo(CommissionRuleSet::class, 'commission_rule_set_id'); }
    public function ruleTier(): BelongsTo { return $this->belongsTo(CommissionRuleTier::class, 'commission_rule_tier_id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function paidBy(): BelongsTo { return $this->belongsTo(User::class, 'paid_by_user_id'); }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? $this->getRouteKeyName(), $value)
            ->when(Auth::check(), fn ($query) => $query->where('organization_id', Auth::user()->active_organization_id));
    }
}
