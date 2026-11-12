<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PaymentRefundSequence;
use App\Services\Sandbox\SandboxContext;
use App\Services\Sandbox\SandboxDocumentNumberGenerator;
use App\Support\LockedRow;
use Illuminate\Support\Facades\DB;
use LogicException;

class PaymentRefundNumberGenerator
{
    public function next(Organization $organization): string
    {
        // Phase 5: simulated Test payments take a non-official TEST- reference
        // and never touch payment_refund_sequences.
        if (app(SandboxContext::class)->isSandbox()) {
            return app(SandboxDocumentNumberGenerator::class)->next($organization, SandboxDocumentNumberGenerator::PAYMENT_REFUND);
        }
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Payment refund numbers may only be allocated inside a transaction.');
        }

        LockedRow::claim('payment_refund_sequences', ['organization_id' => $organization->getKey()], ['next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $sequence = PaymentRefundSequence::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
        $number = $sequence->next_number;
        $sequence->next_number = $number + 1;
        $sequence->save();

        return 'REF-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
