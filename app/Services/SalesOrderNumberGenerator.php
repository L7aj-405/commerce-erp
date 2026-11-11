<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SalesOrderSequence;
use App\Services\Sandbox\SandboxContext;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class SalesOrderNumberGenerator
{
    public function next(Organization $organization): string
    {
        if (app(SandboxContext::class)->isSandbox()) {
            return 'TEST-'.(string) Str::ulid();
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Sales order numbers may only be allocated inside a transaction.');
        }
        LockedRow::claim('sales_order_sequences', ['organization_id' => $organization->getKey()], ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = SalesOrderSequence::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return 'SO-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
