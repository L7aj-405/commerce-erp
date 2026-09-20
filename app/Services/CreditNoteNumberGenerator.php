<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreditNoteNumberGenerator
{
    public function next(Organization $organization, int $year): string
    {
        if (DB::connection()->transactionLevel() < 1) throw new LogicException('Credit Note numbers require an active issuance transaction.');
        DB::table('credit_note_sequences')->insertOrIgnore(['organization_id' => $organization->id, 'year' => $year, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $number = (int) DB::table('credit_note_sequences')->where('organization_id', $organization->id)->where('year', $year)->lockForUpdate()->value('next_number');
        DB::table('credit_note_sequences')->where('organization_id', $organization->id)->where('year', $year)->update(['next_number' => $number + 1, 'updated_at' => now()]);
        return 'AV-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT).'/'.$year;
    }
}
