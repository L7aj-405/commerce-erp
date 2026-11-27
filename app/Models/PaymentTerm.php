<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reusable organization payment term. Descriptive in Phase 1: it does not (yet) drive any due date. */
class PaymentTerm extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['structure' => 'array', 'is_system' => 'boolean', 'active' => 'boolean', 'days' => 'integer'];
    }
}
