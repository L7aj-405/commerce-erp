<?php

namespace App\Models;

use App\Enums\FinancialAccountStatus;
use App\Enums\FinancialAccountType;
use App\Enums\PaymentMethod;
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
            'accepted_methods' => 'array',
        ];
    }

    /**
     * Whether this account may receive a Payment of the given method. A
     * single account can accept several methods (e.g. a bank account
     * receiving transfers, TPE settlements and cheques) — `type` is purely
     * categorisation now, never the compatibility gate.
     */
    public function acceptsMethod(PaymentMethod $method): bool
    {
        return in_array($method->value, $this->accepted_methods ?? [], true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }
}
