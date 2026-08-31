<?php

namespace App\Models;

use App\Enums\FinancialAccountStatus;
use App\Enums\FinancialAccountType;
use App\Models\Concerns\ScopesToActiveOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    use ScopesToActiveOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'type' => FinancialAccountType::class,
            'status' => FinancialAccountStatus::class,
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
