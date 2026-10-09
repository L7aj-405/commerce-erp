<?php

namespace App\Actions\Commissions;

use App\Models\CommissionEntry;
use App\Models\CustomerReturn;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CreateReturnCommissionReversalsAction
{
    public function __construct(private readonly AppendCommissionReversalAction $reversals) {}

    public function execute(User $actor, CustomerReturn $return): int
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Return commission reversals must be generated inside the Return transaction.');
        }

        $return->loadMissing('lines');
        $order = SalesOrder::query()->withoutGlobalScopes()
            ->where('organization_id', $return->organization_id)
            ->whereKey($return->sales_order_id)
            ->firstOrFail();
        $created = 0;
        foreach ($return->lines as $line) {
            $source = CommissionEntry::query()
                ->where('organization_id', $return->organization_id)
                ->where('sales_order_id', $return->sales_order_id)
                ->where('sales_order_line_id', $line->sales_order_line_id)
                ->where('entry_type', CommissionEntry::TYPE_SALE)
                ->when(
                    $order->current_revision_id === null,
                    fn ($query) => $query->whereNull('sales_order_revision_id'),
                    fn ($query) => $query->where('sales_order_revision_id', $order->current_revision_id),
                )
                ->first();
            if (! $source) {
                continue;
            }

            $entry = $this->reversals->execute(
                $actor,
                $source,
                CommissionEntry::TYPE_RETURN_REVERSAL,
                'return:line:'.$line->getKey().':entry:'.$source->getKey(),
                'Retour client '.$return->return_number,
                (string) $line->quantity,
                $return,
                $line,
                $return->received_at ?? now(),
            );
            $created += $entry ? 1 : 0;
        }

        return $created;
    }
}
