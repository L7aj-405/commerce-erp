<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Allocate the next official Devis number for a calendar year.
 *
 * Format is `DEV-N/YYYY`, restarting at 1 each year. The counter row is created
 * on first use and locked FOR UPDATE for the remainder of the surrounding
 * issuance transaction, so concurrent issuances serialise and never collide.
 * This is a DEDICATED sequence — it never touches `invoice_sequences`.
 */
class QuotationNumberGenerator
{
    public function next(Organization $organization, int $year): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Quotation numbers may only be allocated inside an existing issuance transaction.');
        }

        DB::table('quotation_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $number = (int) DB::table('quotation_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->lockForUpdate()
            ->value('next_number');

        DB::table('quotation_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('year', $year)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return 'DEV-'.$number.'/'.$year;
    }
}
