<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocate the next internal transfer-request number: `TRQ-000001`, per
 * organisation, restarting nowhere (a single running counter). The sequence row
 * is created on first use and locked FOR UPDATE for the rest of the surrounding
 * transaction so concurrent confirmations serialise. Independent from
 * `stock_transfer_sequences` (the completed-movement numbers).
 */
class TransferRequestNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Transfer request numbers may only be allocated inside a database transaction.');
        }

        DB::table('transfer_request_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $number = (int) DB::table('transfer_request_sequences')
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->value('next_number');

        DB::table('transfer_request_sequences')
            ->where('organization_id', $organization->getKey())
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return 'TRQ-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
