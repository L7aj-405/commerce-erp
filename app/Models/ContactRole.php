<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A label for what a party is to the organization. Never grants ERP access or financial privileges. */
class ContactRole extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'active' => 'boolean'];
    }
}
