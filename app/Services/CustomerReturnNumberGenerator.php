<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxDocumentNumberGenerator;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use LogicException;

class CustomerReturnNumberGenerator
{
    public function next(Organization $organization, int $year): string
    {
        if (DB::connection()->transactionLevel() < 1) throw new LogicException('Return numbers require an active transaction.');
        // Test Mode: non-official TEST-RET reference; customer_return_sequences is never read or written.
        if (app(SandboxContext::class)->isSandbox()) {
            return app(SandboxDocumentNumberGenerator::class)->next($organization, SandboxDocumentNumberGenerator::CUSTOMER_RETURN);
        }

        LockedRow::claim('customer_return_sequences', ['organization_id' => $organization->id, 'year' => $year], ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $number = (int) DB::table('customer_return_sequences')->where('organization_id', $organization->id)->where('year', $year)->lockForUpdate()->value('next_number');
        DB::table('customer_return_sequences')->where('organization_id', $organization->id)->where('year', $year)->update(['next_number' => $number + 1, 'updated_at' => now()]);
        return 'RET-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT).'/'.$year;
    }
}
