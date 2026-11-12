<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxDocumentNumberGenerator;
use App\Support\LockedRow;
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
        // Test Mode: non-official TEST- reference; purchase_order_sequences is never read or written.
        if (app(SandboxContext::class)->isSandbox()) {
            return app(SandboxDocumentNumberGenerator::class)->next($organization, SandboxDocumentNumberGenerator::PURCHASE_ORDER);
        }

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Purchase order numbers may only be allocated inside a database transaction.');
        }

        LockedRow::claim('purchase_order_sequences', ['organization_id' => $organization->getKey()], ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);

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
