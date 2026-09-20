<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRefundSequence extends Model
{
    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $guarded = ['*'];
}
