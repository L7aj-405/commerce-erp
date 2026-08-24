<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SalesOrderSequence;
use Illuminate\Support\Facades\DB;
use LogicException;

class SalesOrderNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Sales order numbers may only be allocated inside a transaction.');
        }
        DB::table('sales_order_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(), 'next_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = SalesOrderSequence::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return 'SO-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
