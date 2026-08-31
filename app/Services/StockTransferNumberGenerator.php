<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class StockTransferNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stock transfer numbers may only be allocated inside a database transaction.');
        }

        DB::table('stock_transfer_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('stock_transfer_sequences')
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->first();

        $number = (int) $sequence->next_number;

        DB::table('stock_transfer_sequences')
            ->where('organization_id', $organization->getKey())
            ->update([
                'next_number' => $number + 1,
                'updated_at' => now(),
            ]);

        return 'TRF-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
