<?php

namespace App\Services;

use App\Models\DeliveryNoteSequence;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use LogicException;

class DeliveryNoteNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Delivery Note numbers may only be allocated inside an existing issuance transaction.');
        }
        DB::table('delivery_note_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(), 'next_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sequence = DeliveryNoteSequence::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return 'DN-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
