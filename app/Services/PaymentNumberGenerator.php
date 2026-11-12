<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PaymentSequence;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxDocumentNumberGenerator;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentNumberGenerator
{
    public function next(Organization $organization): string
    {
        // Phase 5: simulated Test payments take a non-official TEST- reference
        // and never touch payment_sequences.
        if (app(SandboxContext::class)->isSandbox()) {
            return app(SandboxDocumentNumberGenerator::class)->next($organization, SandboxDocumentNumberGenerator::PAYMENT);
        }
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Payment numbers may only be allocated inside a transaction.');
        }

        LockedRow::claim('payment_sequences', ['organization_id' => $organization->getKey()], ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sequence = PaymentSequence::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return 'PAY-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
