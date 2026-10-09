<?php

namespace App\Actions\Commissions;

use App\Models\CommissionEntry;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class ReverseSalesOrderCommissionsAction
{
    public function __construct(private readonly AppendCommissionReversalAction $reversals) {}

    public function execute(User $actor, SalesOrder $order, string $type, string|int $eventId, string $reason): int
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Sales Order commission reversals must be generated inside the Order transaction.');
        }
        if (! in_array($type, [CommissionEntry::TYPE_CORRECTION, CommissionEntry::TYPE_CANCELLATION], true)) {
            throw new InvalidArgumentException('Unsupported Sales Order commission reversal type.');
        }

        $entries = CommissionEntry::query()
            ->where('organization_id', $order->organization_id)
            ->where('sales_order_id', $order->getKey())
            ->where('entry_type', CommissionEntry::TYPE_SALE)
            ->when(
                $order->current_revision_id === null,
                fn ($query) => $query->whereNull('sales_order_revision_id'),
                fn ($query) => $query->where('sales_order_revision_id', $order->current_revision_id),
            )
            ->orderBy('id')
            ->get();

        $created = 0;
        foreach ($entries as $source) {
            $entry = $this->reversals->execute(
                $actor,
                $source,
                $type,
                $type.':event:'.$eventId.':entry:'.$source->getKey(),
                $reason,
            );
            $created += $entry ? 1 : 0;
        }

        return $created;
    }
}
