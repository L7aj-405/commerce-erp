<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocate the next supplier-procurement number: `APV-000001` (approvisionnement),
 * one running counter per organisation. The sequence row is created on first use
 * and locked FOR UPDATE for the rest of the surrounding transaction so
 * concurrent creations serialise.
 */
class ProcurementNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Procurement numbers may only be allocated inside a database transaction.');
        }

        DB::table('procurement_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $number = (int) DB::table('procurement_sequences')
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->value('next_number');

        DB::table('procurement_sequences')
            ->where('organization_id', $organization->getKey())
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return 'APV-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
