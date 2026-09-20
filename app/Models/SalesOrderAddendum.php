<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrderAddendum extends Model
{
    protected $table = 'sales_order_addenda';

    protected $guarded = ['*'];

    protected $hidden = ['client_operation_id', 'operation_hash'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'before_total' => 'decimal:4',
            'added_total' => 'decimal:4',
            'after_total' => 'decimal:4',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function lines(): HasMany { return $this->hasMany(SalesOrderLine::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function exchange(): HasOne { return $this->hasOne(CustomerExchange::class); }
}
