<?php

namespace App\Services;

use App\Models\Organization;
use App\Support\LockedRow;
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

        $number = $this->lockSequence((int) $organization->getKey());

        DB::table('transfer_request_sequences')
            ->where('organization_id', $organization->getKey())
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return 'TRQ-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Lock the organization's sequence row FOR UPDATE until the surrounding
     * transaction ends, creating it on first use; returns `next_number`.
     * Also the organization-wide planning lock (ShowroomReplenishmentPlanner).
     * See LockedRow for why this must not be SELECT FOR UPDATE + INSERT IGNORE
     * (gap-lock and shared→exclusive upgrade deadlocks on MySQL).
     */
    public function lockSequence(int $organizationId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The transfer request sequence may only be locked inside a database transaction.');
        }

        LockedRow::claim('transfer_request_sequences', ['organization_id' => $organizationId], ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('transfer_request_sequences')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->value('next_number');
    }
}
