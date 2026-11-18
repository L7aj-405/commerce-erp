<?php

namespace Tests\Unit\Architecture\Fixtures;

use App\Models\Payment;
use App\Models\PaymentSequence;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

/**
 * Synthetic code analysed (never executed) by TenantScopingTest to prove the detector really
 * discriminates. Methods prefixed `unsafe` MUST be reported, methods prefixed `safe` MUST NOT.
 * Not autoloaded by the application and not a test (no `Test` suffix).
 */
final class TenantScopeFixtures
{
    public function unsafeByKey(int $id)
    {
        return Payment::query()->whereKey($id)->first();
    }

    public function unsafeStaticFind(int $id)
    {
        return SalesOrder::find($id);
    }

    public function unsafeFilteredOnlyByStatus()
    {
        return Payment::query()->where('status', 'posted')->get();
    }

    public function unsafeRawTable()
    {
        return DB::table('payments')->where('status', 'posted')->sum('amount');
    }

    public function unsafeRawTableAlias()
    {
        return DB::table('payments as p')->get();
    }

    public function unsafeBuilderVariableNeverScoped()
    {
        $query = Payment::query();

        return $query->where('status', 'posted')->get();
    }

    public function safeLiteralColumn(int $organizationId)
    {
        return Payment::query()->where('organization_id', $organizationId)->whereKey(1)->first();
    }

    public function safeArrayConditions(int $organizationId)
    {
        return Payment::query()->where(['organization_id' => $organizationId, 'status' => 'posted'])->get();
    }

    public function safeQualifiedColumn(int $organizationId)
    {
        return DB::table('payments')->where('payments.organization_id', $organizationId)->sum('amount');
    }

    public function safeJoinCondition()
    {
        return DB::table('payments')->join('payment_allocations', fn ($join) => $join->on('payments.organization_id', '=', 'payment_allocations.organization_id'))->get();
    }

    public function safeBuilderScopedLater(int $organizationId)
    {
        $query = Payment::query();

        return $query->where('organization_id', $organizationId)->get();
    }

    public function safeBuilderScopedThroughTernary(bool $flag, int $organizationId)
    {
        $query = $flag ? Payment::query()->where('status', 'posted') : Payment::query();

        return $query->where('organization_id', $organizationId)->get();
    }

    public function safeSequenceRowKeyedByOrganization($organization)
    {
        return PaymentSequence::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
    }

    public function safeKnownScopingHelper($organization)
    {
        return Payment::query()->forOrganization($organization)->get();
    }

    public function safeRelationTraversalIsNotARoot($order)
    {
        return $order->payments()->where('status', 'posted')->get();
    }
}
