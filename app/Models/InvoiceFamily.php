<?php

namespace App\Models;

use App\Models\Concerns\ScopesToActiveOrganization;
use App\Models\Concerns\ScopesToSandbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceFamily extends Model
{
    use ScopesToActiveOrganization;
    use ScopesToSandbox;

    protected $guarded = ['*'];

    /** A family has no lifecycle status; ownership alone isolates it. */
    public function sandboxStatuses(): ?array
    {
        return null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderByDesc('version');
    }
}
