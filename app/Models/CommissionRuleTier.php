<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRuleTier extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'min_margin_rate' => 'decimal:4',
            'max_margin_rate' => 'decimal:4',
            'commission_rate' => 'decimal:4',
        ];
    }

    public function ruleSet(): BelongsTo { return $this->belongsTo(CommissionRuleSet::class, 'commission_rule_set_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
}
