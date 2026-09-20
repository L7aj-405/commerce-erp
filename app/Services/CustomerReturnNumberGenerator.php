<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class CustomerReturnNumberGenerator
{
    public function next(Organization $organization, int $year): string
    {
        if (DB::connection()->transactionLevel() < 1) throw new LogicException('Return numbers require an active transaction.');
        DB::table('customer_return_sequences')->insertOrIgnore(['organization_id' => $organization->id, 'year' => $year, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $number = (int) DB::table('customer_return_sequences')->where('organization_id', $organization->id)->where('year', $year)->lockForUpdate()->value('next_number');
        DB::table('customer_return_sequences')->where('organization_id', $organization->id)->where('year', $year)->update(['next_number' => $number + 1, 'updated_at' => now()]);
        return 'RET-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT).'/'.$year;
    }
}
