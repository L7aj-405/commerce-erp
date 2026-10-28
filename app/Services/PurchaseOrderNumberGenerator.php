<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocate the next supplier purchase order number: `BCF-000001` (bon de
 * commande fournisseur), one running counter per organisation, from a sequence
 * row locked FOR UPDATE for the rest of the surrounding transaction.
 */
class PurchaseOrderNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Purchase order numbers may only be allocated inside a database transaction.');
        }

        DB::table('purchase_order_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $number = (int) DB::table('purchase_order_sequences')
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->value('next_number');

        DB::table('purchase_order_sequences')
            ->where('organization_id', $organization->getKey())
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return 'BCF-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
